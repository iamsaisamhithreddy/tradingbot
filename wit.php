<?php
// === PHP BACKEND ===

// 1. Require your database and config file safely
require_once 'db.php'; 

// --- FILE STORAGE & CLEANUP ROUTINE ---
$audio_dir = __DIR__ . '/shared_audio/';
if (!is_dir($audio_dir)) {
    mkdir($audio_dir, 0777, true);
}

// Delete files older than 1 hour (3600 seconds)
$files = glob($audio_dir . '*.wav');
$now = time();
foreach ($files as $file) {
    if (is_file($file) && ($now - filemtime($file) >= 3600)) {
        unlink($file);
    }
}

// Helper to get absolute URL for sharing
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . rtrim(dirname($_SERVER['PHP_SELF']), '/');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // 1. Handle Text-to-Speech
    if ($_POST['action'] === 'tts') {
        $text = $_POST['text'] ?? '';
        $voice = $_POST['voice'] ?? 'Rebecca'; 
        
        $ch = curl_init("https://api.wit.ai/synthesize?v={$witVersion}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['q' => $text, 'voice' => $voice]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $witAiToken,
                'Content-Type: application/json',
                'Accept: audio/wav'
            ]
        ]);

        $audio_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            // Save file and generate link
            $filename = uniqid('tts_') . '.wav';
            file_put_contents($audio_dir . $filename, $audio_data);
            $shareable_link = $base_url . '/shared_audio/' . $filename;

            echo json_encode([
                'status' => 'success', 
                'audio_base64' => base64_encode($audio_data),
                'shareable_link' => $shareable_link
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => "TTS API Error: $http_code"]);
        }
        exit;
    }

    // 2. Handle Speech-to-Text (from Mic)
    if ($_POST['action'] === 'stt') {
        if (isset($_FILES['audio'])) {
            $audio_data = file_get_contents($_FILES['audio']['tmp_name']);

            // Save the user's uploaded mic recording
            $filename = uniqid('stt_') . '.wav';
            file_put_contents($audio_dir . $filename, $audio_data);
            $shareable_link = $base_url . '/shared_audio/' . $filename;

            $ch = curl_init("https://api.wit.ai/speech?v={$witVersion}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $audio_data,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $witAiToken,
                    'Content-Type: audio/wav'
                ]
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                $lines = array_filter(explode("\n", trim($response)));
                $final_json = end($lines);
                $parsed = json_decode($final_json, true);
                
                $text = $parsed['text'] ?? "Audio processed, but no text was recognized.";
                echo json_encode([
                    'status' => 'success', 
                    'text' => $text,
                    'shareable_link' => $shareable_link
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "STT API Error: $http_code\nResponse: $response"]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No audio file received.']);
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wit.ai Voice Assistant</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f0f2f5; padding: 2rem; color: #333; }
        .card { max-width: 600px; margin: 0 auto 2rem auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { margin-top: 0; color: #1a73e8; }
        input[type="text"], select { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; font-size: 1rem;}
        button { background: #1a73e8; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:hover { background: #1557b0; }
        button.recording { background: #d93025; animation: pulse 1.5s infinite; }
        .output { margin-top: 15px; padding: 15px; background: #e8f0fe; border-radius: 4px; display: none; }
        .share-link { margin-top: 10px; font-size: 0.9rem; word-break: break-all; }
        .share-link a { color: #1a73e8; font-weight: bold; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }
    </style>
</head>
<body>

    <div class="card">
        <h2>Speak Text (TTS)</h2>
        
        <label for="ttsVoice"><strong>Select Voice/Language:</strong></label>
        <select id="ttsVoice">
            <option value="Rebecca">English (US) - Rebecca (Female)</option>
            <option value="Ronald">English (US) - Ronald (Male)</option>
            <option value="Colin">English (US) - Colin (Male)</option>
            <option value="Charlie">English (US) - Charlie (Male)</option>
            </select>

        <input type="text" id="ttsInput" placeholder="Type something for the AI to say...">
        <button onclick="synthesizeText()">Generate Audio</button>
        <div id="ttsOutput" class="output"></div>
    </div>

    <div class="card">
        <h2>Microphone (STT)</h2>
        <p>Click "Start Recording", speak into your mic, then click "Stop".</p>
        <button id="recordBtn" onclick="toggleRecording()">Start Recording</button>
        <div id="sttOutput" class="output"></div>
    </div>

    <script>
        // --- TEXT TO SPEECH ---
        async function synthesizeText() {
            const text = document.getElementById('ttsInput').value;
            const voice = document.getElementById('ttsVoice').value; 
            
            if (!text) return alert('Please enter some text');
            
            const outDiv = document.getElementById('ttsOutput');
            outDiv.style.display = 'block';
            outDiv.innerHTML = 'Generating audio...';

            const formData = new FormData();
            formData.append('action', 'tts');
            formData.append('text', text);
            formData.append('voice', voice);

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.status === 'success') {
                    outDiv.innerHTML = `
                        <audio controls autoplay src="data:audio/wav;base64,${data.audio_base64}"></audio>
                        <div class="share-link">
                            🔗 <strong>Shareable Link (Expires in 1 Hour):</strong><br>
                            <a href="${data.shareable_link}" target="_blank">${data.shareable_link}</a>
                        </div>
                    `;
                } else {
                    outDiv.innerHTML = `<span style="color:red">Error: ${data.message}</span>`;
                }
            } catch (err) {
                outDiv.innerHTML = `<span style="color:red">Request failed.</span>`;
            }
        }

        // --- MICROPHONE TO TEXT (WITH WAV CONVERTER) ---
        let mediaRecorder;
        let audioChunks = [];
        let isRecording = false;

        async function toggleRecording() {
            const btn = document.getElementById('recordBtn');
            const outDiv = document.getElementById('sttOutput');

            if (!isRecording) {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];

                    mediaRecorder.ondataavailable = e => {
                        if (e.data.size > 0) audioChunks.push(e.data);
                    };

                    mediaRecorder.onstop = async () => {
                        btn.innerText = 'Processing...';
                        outDiv.style.display = 'block';
                        outDiv.innerHTML = 'Converting and sending to Wit.ai...';

                        const webmBlob = new Blob(audioChunks, { type: mediaRecorder.mimeType });
                        
                        const arrayBuffer = await webmBlob.arrayBuffer();
                        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                        const audioBuffer = await audioContext.decodeAudioData(arrayBuffer);
                        const wavBlob = audioBufferToWav(audioBuffer);
                        
                        const formData = new FormData();
                        formData.append('action', 'stt');
                        formData.append('audio', wavBlob, 'recording.wav'); 

                        try {
                            const response = await fetch('', { method: 'POST', body: formData });
                            const data = await response.json();
                            
                            if (data.status === 'success') {
                                outDiv.innerHTML = `
                                    <strong>You said:</strong> "${data.text}"
                                    <div class="share-link">
                                        🔗 <strong>Shareable Link to your recording (Expires in 1 Hour):</strong><br>
                                        <a href="${data.shareable_link}" target="_blank">${data.shareable_link}</a>
                                    </div>
                                `;
                            } else {
                                outDiv.innerHTML = `<span style="color:red">Error: ${data.message}</span>`;
                            }
                        } catch (err) {
                            outDiv.innerHTML = `<span style="color:red">Network Error. Check console.</span>`;
                        }
                        
                        btn.innerText = 'Start Recording';
                    };

                    mediaRecorder.start();
                    isRecording = true;
                    btn.innerText = 'Stop Recording';
                    btn.classList.add('recording');
                    outDiv.style.display = 'none';

                } catch (err) {
                    alert('Microphone access denied or not available.');
                    console.error(err);
                }
            } else {
                mediaRecorder.stop();
                mediaRecorder.stream.getTracks().forEach(track => track.stop());
                isRecording = false;
                btn.classList.remove('recording');
            }
        }

        // --- HELPER: PURE JAVASCRIPT WAV ENCODER ---
        function audioBufferToWav(buffer) {
            let numOfChan = buffer.numberOfChannels,
                length = buffer.length * numOfChan * 2 + 44,
                bufferArray = new ArrayBuffer(length),
                view = new DataView(bufferArray),
                channels = [], i, sample, offset = 0, pos = 0;

            setUint32(0x46464952); setUint32(length - 8); setUint32(0x45564157);
            setUint32(0x20746d66); setUint32(16); setUint16(1); setUint16(numOfChan);
            setUint32(buffer.sampleRate); setUint32(buffer.sampleRate * 2 * numOfChan);
            setUint16(numOfChan * 2); setUint16(16);
            setUint32(0x61746164); setUint32(length - pos - 4);

            for(i = 0; i < buffer.numberOfChannels; i++) channels.push(buffer.getChannelData(i));
            
            while(pos < buffer.length) {
                for(i = 0; i < numOfChan; i++) {
                    sample = Math.max(-1, Math.min(1, channels[i][pos])); 
                    sample = (0.5 + sample < 0 ? sample * 32768 : sample * 32767)|0; 
                    view.setInt16(offset, sample, true); offset += 2;
                }
                pos++;
            }

            function setUint16(data) { view.setUint16(offset, data, true); offset += 2; }
            function setUint32(data) { view.setUint32(offset, data, true); offset += 4; }

            return new Blob([bufferArray], { type: "audio/wav" });
        }
    </script>
</body>
</html>