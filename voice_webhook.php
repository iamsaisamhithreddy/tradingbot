<?php
// ==========================================
// voice_webhook.php
// Pure Voice-to-Text -> AI -> Text-to-Voice

// 1. Require your database and config file safely
require_once 'db.php'; 

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Ensure the website URL is built from the botToken inside db.php
$website = "https://api.telegram.org/bot" . $botToken; 

// 2. The AI Calling Function
function callTradingAI($question, $endpoint){
    $post = ['source' => 'telegram', 'question' => $question];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($post), 
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 20
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    
    if(!$res) return "I'm sorry, the AI service is currently unavailable.";
    $json = json_decode($res, true);
    return $json['reply'] ?? "I'm sorry, I couldn't generate a response.";
}

// 3. Read incoming Telegram data
$update = file_get_contents("php://input");
$update_array = json_decode($update, TRUE);

http_response_code(200);

// VOICE HANDLER
if(isset($update_array['message']['voice'])) {
    
    $chatId    = $update_array['message']['chat']['id'];
    $messageId = $update_array['message']['message_id'];
    $fileId    = $update_array['message']['voice']['file_id'];
    
    $getFileUrl = $website . "/getFile?file_id={$fileId}";
    $fileResponse = json_decode(@file_get_contents($getFileUrl), true);
    
    if ($fileResponse['ok']) {
        $filePath = $fileResponse['result']['file_path'];
        $downloadUrl = str_replace("/bot", "/file/bot", $website) . "/" . $filePath;
        $audioData = @file_get_contents($downloadUrl);
        
        if ($audioData) {
            // STEP A: Download OGG & Convert to WAV for Wit.ai (Speech-to-Text)
            $tempOgg = sys_get_temp_dir() . '/tg_voice_' . uniqid() . '.ogg';
            $tempWav = sys_get_temp_dir() . '/tg_voice_' . uniqid() . '.wav';
            file_put_contents($tempOgg, $audioData);
            
            @chmod(__DIR__ . '/ffmpeg', 0755);
            $ffmpegCommand = __DIR__ . "/ffmpeg -y -i " . escapeshellarg($tempOgg) . " -ar 16000 -ac 1 " . escapeshellarg($tempWav) . " 2>&1";
            shell_exec($ffmpegCommand);
            
            $finalAudioData = (file_exists($tempWav) && filesize($tempWav) > 0) ? file_get_contents($tempWav) : $audioData;
            $contentType = (file_exists($tempWav) && filesize($tempWav) > 0) ? 'audio/wav' : 'audio/ogg';

            // STEP B: Send audio to Wit.ai to get Text
            // Note: $witVersion and $witAiToken are imported directly from db.php
            $ch = curl_init("https://api.wit.ai/speech?v={$witVersion}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $finalAudioData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $witAiToken", "Content-Type: $contentType"]);
            $witResponse = curl_exec($ch);
            curl_close($ch);
            
            @unlink($tempOgg);
            @unlink($tempWav);
            
            // Parse the transcribed text
            $transcribedText = "Sorry, I couldn't process the audio.";
            if ($witResponse && preg_match_all('/"text"\s*:\s*"([^"]+)"/', $witResponse, $matches)) {
                $validTexts = array_filter($matches[1], 'trim');
                if (!empty($validTexts)) {
                    $transcribedText = end($validTexts);
                }
            }

            // STEP C: Send Text to Trading AI
            if ($transcribedText !== "Sorry, I couldn't process the audio.") {
                
                // Show "recording voice" action in Telegram so the user knows bot is thinking
                @file_get_contents($website . "/sendChatAction?chat_id={$chatId}&action=record_voice");
                
                // Use the endpoint directly from db.php
                $endpointToUse = $AI_ENDPOINT;
                
                // Get answer from AI
                $aiReply = callTradingAI($transcribedText, $endpointToUse);
                
                // STEP D: Send AI Answer to Wit.ai to get Voice Audio (Text-to-Speech)
                $ttsData = json_encode(['q' => $aiReply, 'voice' => 'Rebecca']);
                $chTTS = curl_init("https://api.wit.ai/synthesize?v={$witVersion}");
                curl_setopt($chTTS, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($chTTS, CURLOPT_POST, true);
                curl_setopt($chTTS, CURLOPT_POSTFIELDS, $ttsData);
                curl_setopt($chTTS, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $witAiToken",
                    "Content-Type: application/json",
                    "Accept: audio/wav"
                ]);
                $ttsRawAudio = curl_exec($chTTS);
                curl_close($chTTS);

                // STEP E: Convert TTS WAV back to Telegram OPUS (OGG) and send
                if ($ttsRawAudio) {
                    $tempTtsWav = sys_get_temp_dir() . '/tts_' . uniqid() . '.wav';
                    $tempTtsOgg = sys_get_temp_dir() . '/tts_' . uniqid() . '.ogg';
                    file_put_contents($tempTtsWav, $ttsRawAudio);

                    $ffmpegTtsCmd = __DIR__ . "/ffmpeg -y -i " . escapeshellarg($tempTtsWav) . " -c:a libopus " . escapeshellarg($tempTtsOgg) . " 2>&1";
                    shell_exec($ffmpegTtsCmd);

                    $finalVoiceFile = file_exists($tempTtsOgg) ? $tempTtsOgg : $tempTtsWav;

                    $cfile = new CURLFile(realpath($finalVoiceFile));
                    $postVoice = [
                        'chat_id'             => $chatId,
                        'voice'               => $cfile,
                        'caption'             => "🗣️ You asked: *$transcribedText*", // Shows what the bot heard
                        'parse_mode'          => 'Markdown',
                        'reply_to_message_id' => $messageId
                    ];
                    
                    $chVoice = curl_init($website . '/sendVoice');
                    curl_setopt($chVoice, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chVoice, CURLOPT_POST, true);
                    curl_setopt($chVoice, CURLOPT_POSTFIELDS, $postVoice);
                    curl_setopt($chVoice, CURLOPT_SSL_VERIFYPEER, false);
                    curl_exec($chVoice);
                    curl_close($chVoice);
                    
                    @unlink($tempTtsWav);
                    @unlink($tempTtsOgg);
                } else {
                    // Fallback: If Wit.ai TTS crashes, just send a regular text message
                    $fallbackMsg = "🗣️ *You asked:* $transcribedText\n\n🤖 *AI:* " . $aiReply;
                    $postMsg = [
                        'chat_id'    => $chatId,
                        'text'       => $fallbackMsg,
                        'parse_mode' => 'Markdown'
                    ];
                    $chText = curl_init($website . '/sendMessage');
                    curl_setopt($chText, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chText, CURLOPT_POST, true);
                    curl_setopt($chText, CURLOPT_POSTFIELDS, $postMsg);
                    curl_setopt($chText, CURLOPT_SSL_VERIFYPEER, false);
                    curl_exec($chText);
                    curl_close($chText);
                }
            } else {
                @file_get_contents($website . "/sendMessage?chat_id={$chatId}&text=" . urlencode("⚠️ Could not hear what you said."));
            }
        } 
    } 
}
?>