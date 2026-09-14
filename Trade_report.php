<?php
/* ============================================================
   TRADE ANALYTICS REPORT  -  v3
   Adds: equity curve, streaks/drawdown, expectancy & profit
   factor, Wilson confidence intervals, setup-formation rate,
   currency attribution, weekday x hour heatmap, time-to-
   resolution, direction bias per pair, pending aging,
   period-over-period deltas, auto executive summary.
   ============================================================ */

session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

require('fpdf.php');
require 'db.php';

/* ============================================================
   COLOR CONSTANTS
   ============================================================ */
define('C_NAVY',   '25,42,86');
define('C_BLUE',   '52,120,246');
define('C_GREEN',  '34,167,110');
define('C_RED',    '224,67,79');
define('C_AMBER',  '247,181,56');
define('C_GREY',   '150,158,172');
define('C_PURPLE', '142,84,233');
define('C_TEAL',   '0,178,191');

function rgb($c){ return array_map('intval', explode(',', $c)); }

/* ============================================================
   PDF CLASS
   ============================================================ */
class PDF extends FPDF {
    public $headers = ['Trade ID','Chart','Pair','Target','Dir','Time','Session','Result'];
    public $widths  = [17,13,26,24,13,17,38,42];
    public $showHeaderBar = false;
    public $sectionName = '';

    function Header() {
        if($this->PageNo() > 1 && $this->showHeaderBar) {
            list($r,$g,$b) = rgb(C_NAVY);
            $this->SetFillColor($r,$g,$b);
            $this->Rect(0,0,210,14,'F');
            list($r,$g,$b) = rgb(C_BLUE);
            $this->SetFillColor($r,$g,$b);
            $this->Rect(0,14,210,1.2,'F');
            $this->SetFont('Arial','B',10);
            $this->SetTextColor(255,255,255);
            $this->SetXY(10,3.5);
            $this->Cell(120,7,'TRADE ANALYTICS REPORT',0,0,'L');
            $this->SetFont('Arial','',8);
            $this->SetTextColor(180,198,235);
            $this->Cell(70,7,$this->sectionName,0,0,'R');
            $this->SetY(22);
        }
    }

    function Footer() {
        $this->SetY(-14);
        $this->SetDrawColor(215,220,230);
        $this->SetLineWidth(0.2);
        $this->Line(10,$this->GetY(),200,$this->GetY());
        $this->SetFont('Arial','I',7.5);
        $this->SetTextColor(130,138,150);
        $this->Cell(0,9,'Generated '.date('d M Y H:i').'   |   Page '.$this->PageNo().' of {nb}',0,0,'C');
    }

    /* ---------- LAYOUT GUARDS ---------- */

    function NeedSpace($h){
        if($this->GetY() + $h > ($this->h - 20)){
            $this->AddPage();
            $this->SetY(22);
            return true;
        }
        return false;
    }

    function LockPage(){ $this->SetAutoPageBreak(false); }
    function UnlockPage(){ $this->SetAutoPageBreak(true, 20); }

    /* ---------- TEXT / TITLES ---------- */

    // $reserve = height of the chart that follows, so title + chart stay together.
    // Charts run with auto page-break disabled, so without this they draw off-page.
    function SectionTitle($txt,$sub='',$reserve=0){
        $this->NeedSpace(($sub!=='' ? 20 : 16) + $reserve);
        list($r,$g,$b) = rgb(C_NAVY);
        $this->SetFont('Arial','B',12);
        $this->SetTextColor($r,$g,$b);
        $this->Cell(0,7,$txt,0,1,'L');
        if($sub !== ''){
            $this->SetFont('Arial','',8);
            $this->SetTextColor(120,128,142);
            $this->Cell(0,4,$sub,0,1,'L');
        }
        list($r,$g,$b) = rgb(C_BLUE);
        $this->SetDrawColor($r,$g,$b);
        $this->SetLineWidth(0.5);
        $this->Line(10,$this->GetY()+1,42,$this->GetY()+1);
        $this->SetDrawColor(220,225,235);
        $this->SetLineWidth(0.2);
        $this->Line(42,$this->GetY()+1,200,$this->GetY()+1);
        $this->Ln(5);
    }

    // KPI tile with optional delta vs previous period
    function StatBox($x,$y,$w,$h,$label,$value,$col,$small=false,$delta=null,$deltaSuffix=''){
        list($r,$g,$b) = rgb($col);
        $this->SetFillColor($r,$g,$b);
        $this->Rect($x,$y,$w,$h,'F');
        $this->SetFillColor(min(255,$r+30),min(255,$g+30),min(255,$b+30));
        $this->Rect($x,$y,$w,1.6,'F');

        $this->SetXY($x,$y+3.2);
        $this->SetFont('Arial','B',$small?13:17);
        $this->SetTextColor(255,255,255);
        $this->Cell($w,$small?7:8,$value,0,2,'C');
        $this->SetFont('Arial','',6.8);
        $this->SetTextColor(232,238,250);
        $this->Cell($w,4,$label,0,0,'C');

        if($delta !== null){
            $arrow = $delta > 0 ? chr(94) : ($delta < 0 ? 'v' : '-');
            $txt = $arrow.' '.($delta>0?'+':'').round($delta,1).$deltaSuffix;
            $this->SetXY($x,$y+$h-4.6);
            $this->SetFont('Arial','B',6.2);
            if($delta > 0)      $this->SetTextColor(190,255,220);
            elseif($delta < 0)  $this->SetTextColor(255,205,205);
            else                $this->SetTextColor(225,230,240);
            $this->Cell($w,4,$txt,0,0,'C');
        }
    }

    /* ---------- PIE / DONUT ---------- */

    function PieChart($cx,$cy,$r,$data,$colors,$donut=false,$centerLabel=''){
        $this->LockPage();
        $total = array_sum($data);
        if($total <= 0){ $this->UnlockPage(); return; }
        $angle = 0; $i = 0;
        foreach($data as $val){
            $slice = ($val/$total)*360;
            if($slice <= 0){ $i++; continue; }
            list($cr,$cg,$cb) = $colors[$i % count($colors)];
            $this->SetFillColor($cr,$cg,$cb);
            $this->Sector($cx,$cy,$r,$angle,$angle+$slice);
            $angle += $slice; $i++;
        }
        if($donut){
            $this->SetFillColor(255,255,255);
            $this->Circle($cx,$cy,$r*0.58,'F');
            $this->SetXY($cx-$r,$cy-5);
            $this->SetFont('Arial','B',13);
            $this->SetTextColor(35,42,58);
            $this->Cell($r*2,6,$centerLabel!==''?$centerLabel:(string)$total,0,2,'C');
            $this->SetFont('Arial','',6);
            $this->SetTextColor(140,148,162);
            $this->Cell($r*2,3,'TOTAL',0,0,'C');
        }
        $this->UnlockPage();
    }

    function Sector($xc,$yc,$r,$a,$b,$style='F',$cw=true,$o=90){
        if($cw){ $d=$b; $b=$o-$a; $a=$o-$d; } else { $b+=$o; $a+=$o; }
        while($a<0) $a+=360;  while($a>360) $a-=360;
        while($b<0) $b+=360;  while($b>360) $b-=360;
        if($a>$b) $b+=360;
        $b=$b/360*2*M_PI; $a=$a/360*2*M_PI;
        $d=$b-$a; if($d==0) $d=2*M_PI;
        $k=$this->k; $hp=$this->h;
        $nc = max(2, (int)ceil(12*$d/(2*M_PI)));
        $this->_out(sprintf('%.2F %.2F m',$xc*$k,($hp-$yc)*$k));
        $a3 = $a + $d/$nc;
        $this->_out(sprintf('%.2F %.2F l',($xc+$r*cos($a))*$k,(($hp-($yc-$r*sin($a)))*$k)));
        for($i=1;$i<=$nc;$i++){
            $a2 = $a3;
            $x0 = $xc+$r*cos($a3-$d/$nc); $y0 = $yc-$r*sin($a3-$d/$nc);
            $x1 = $xc+$r*cos($a2);        $y1 = $yc-$r*sin($a2);
            $mm = $d/$nc/2;
            $hh = 4/3*(1-cos($mm))/sin($mm)*$r;
            $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
                ($x0+$hh*cos($a3-$d/$nc+M_PI/2))*$k,($hp-($y0-$hh*sin($a3-$d/$nc+M_PI/2)))*$k,
                ($x1+$hh*cos($a2-M_PI/2))*$k,($hp-($y1-$hh*sin($a2-M_PI/2)))*$k,
                $x1*$k,($hp-$y1)*$k));
            $a3 += $d/$nc;
        }
        $this->_out('h f');
    }

    function Circle($x,$y,$r,$style='D'){ $this->Ellipse($x,$y,$r,$r,$style); }

    function Ellipse($x,$y,$rx,$ry,$style='D'){
        $op = ($style=='F') ? 'f' : (($style=='FD'||$style=='DF') ? 'B' : 'S');
        $lx = 4/3*(M_SQRT2-1)*$rx; $ly = 4/3*(M_SQRT2-1)*$ry;
        $k = $this->k; $h = $this->h;
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x+$rx)*$k,($h-$y)*$k,($x+$rx)*$k,($h-($y-$ly))*$k,
            ($x+$lx)*$k,($h-($y-$ry))*$k,$x*$k,($h-($y-$ry))*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x-$lx)*$k,($h-($y-$ry))*$k,($x-$rx)*$k,($h-($y-$ly))*$k,($x-$rx)*$k,($h-$y)*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c',
            ($x-$rx)*$k,($h-($y+$ly))*$k,($x-$lx)*$k,($h-($y+$ry))*$k,$x*$k,($h-($y+$ry))*$k));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c %s',
            ($x+$lx)*$k,($h-($y+$ry))*$k,($x+$rx)*$k,($h-($y+$ly))*$k,($x+$rx)*$k,($h-$y)*$k,$op));
    }

    /* ---------- LEGEND ---------- */

    function Legend($x,$y,$items,$colors,$total=0){
        if($total<=0) $total = array_sum($items) ?: 1;
        $i=0;
        foreach($items as $label => $val){
            list($r,$g,$b) = $colors[$i % count($colors)];
            $this->SetFillColor($r,$g,$b);
            $this->Rect($x,$y+$i*6,3.6,3.6,'F');
            $this->SetFont('Arial','B',8);
            $this->SetTextColor(45,52,66);
            $this->SetXY($x+5.6,$y+$i*6-1.4);
            $this->Cell(26,5,$label,0,0,'L');
            $this->SetFont('Arial','',8);
            $this->SetTextColor(110,118,132);
            $this->Cell(26,5,$val.'  ('.round($val/$total*100,1).'%)',0,0,'L');
            $i++;
        }
        return $y + count($items)*6;
    }

    function InlineLegend($x,$y,$items){
        $lx = $x;
        foreach($items as $lbl=>$c){
            list($r,$g,$b) = rgb($c);
            $this->SetFillColor($r,$g,$b);
            $this->Rect($lx,$y+1,3.2,3.2,'F');
            $this->SetXY($lx+4.5,$y-0.4);
            $this->SetFont('Arial','',7.5);
            $this->SetTextColor(90,98,112);
            $w = $this->GetStringWidth($lbl)+2;
            $this->Cell($w,5,$lbl,0,0,'L');
            $lx += 4.5 + $w + 6;
        }
        return $y+6;
    }

    /* ---------- HORIZONTAL BAR ---------- */

    function HBarChart($x,$y,$w,$data,$colors,$barH=6.5,$gap=3.2,$labelW=30){
        $this->LockPage();
        $max = count($data) ? max(array_values($data)) : 1;
        if($max<=0) $max=1;
        $track = $w - $labelW - 14;
        $i=0;
        foreach($data as $label => $val){
            $cy = $y + $i*($barH+$gap);
            $this->SetFont('Arial','',8);
            $this->SetTextColor(60,68,82);
            $this->SetXY($x,$cy);
            $this->Cell($labelW,$barH,$label,0,0,'L');
            $bw = ($val/$max)*$track;
            list($r,$g,$b) = $colors[$i % count($colors)];
            $this->SetFillColor(236,240,247);
            $this->Rect($x+$labelW,$cy+0.6,$track,$barH-1.2,'F');
            $this->SetFillColor($r,$g,$b);
            if($bw>0.4) $this->Rect($x+$labelW,$cy+0.6,$bw,$barH-1.2,'F');
            $this->SetXY($x+$labelW+$track+1,$cy);
            $this->SetFont('Arial','B',8);
            $this->SetTextColor(35,42,58);
            $this->Cell(12,$barH,$val,0,0,'R');
            $i++;
        }
        $this->UnlockPage();
        return $y + $i*($barH+$gap);
    }

    /* ---------- STACKED WIN / LOSS (with sample-size honesty) ---------- */

    function StackedBar($x,$y,$w,$data,$barH=7,$gap=4,$labelW=30,$minSample=5,$showCI=false,$benchmark=0.5){
        $this->LockPage();
        list($gr,$gg,$gb) = rgb(C_GREEN);
        list($rr,$rg,$rb) = rgb(C_RED);
        $track = $w - $labelW - ($showCI ? 52 : 34);
        $i=0;
        foreach($data as $label => $d){
            $tot = $d['w']+$d['l'];
            $cy = $y + $i*($barH+$gap);
            $thin = ($tot > 0 && $tot < $minSample);

            $this->SetFont('Arial',$thin?'':'',8);
            $this->SetTextColor($thin?140:60, $thin?146:68, $thin?158:82);
            $this->SetXY($x,$cy);
            $this->Cell($labelW,$barH,$label,0,0,'L');

            if($tot <= 0){
                $this->SetFillColor(240,242,246);
                $this->Rect($x+$labelW,$cy+0.6,$track,$barH-1.2,'F');
                $this->SetXY($x+$labelW+$track+2,$cy);
                $this->SetFont('Arial','',8);
                $this->SetTextColor(150,156,168);
                $this->Cell(50,$barH,'no resolved trades',0,0,'L');
                $i++; continue;
            }

            $ww = ($d['w']/$tot)*$track;
            $lw = ($d['l']/$tot)*$track;

            // muted palette when sample is too small to trust
            if($thin){
                $this->SetFillColor(168,206,188);
                if($ww>0.3) $this->Rect($x+$labelW,$cy+0.6,$ww,$barH-1.2,'F');
                $this->SetFillColor(233,183,187);
                if($lw>0.3) $this->Rect($x+$labelW+$ww,$cy+0.6,$lw,$barH-1.2,'F');
            } else {
                $this->SetFillColor($gr,$gg,$gb);
                if($ww>0.3) $this->Rect($x+$labelW,$cy+0.6,$ww,$barH-1.2,'F');
                $this->SetFillColor($rr,$rg,$rb);
                if($lw>0.3) $this->Rect($x+$labelW+$ww,$cy+0.6,$lw,$barH-1.2,'F');
            }

            // break-even marker (payout-derived, not 50%)
            $bmx = $x+$labelW+$benchmark*$track;
            $this->SetDrawColor(20,20,20);
            $this->SetLineWidth(0.5);
            $this->Line($bmx,$cy,$bmx,$cy+$barH);
            $this->SetLineWidth(0.2);

            $ratio = $d['w']/$tot;
            $pct = round($ratio*100);
            $this->SetXY($x+$labelW+$track+2,$cy);
            $this->SetFont('Arial','B',8);
            if($thin)                            $this->SetTextColor(150,156,168);
            elseif($ratio >= $benchmark + 0.05)  $this->SetTextColor($gr,$gg,$gb);
            elseif($ratio <  $benchmark)         $this->SetTextColor($rr,$rg,$rb);
            else                                 $this->SetTextColor(190,140,20);
            $this->Cell(30,$barH,$pct.'%   '.$d['w'].'W / '.$d['l'].'L',0,0,'L');

            if($showCI){
                $ci = wilson($d['w'],$tot);
                $this->SetFont('Arial','',7);
                $this->SetTextColor(150,156,168);
                $this->SetXY($x+$labelW+$track+32,$cy);
                $this->Cell(22,$barH,'CI '.round($ci['lo']*100).'-'.round($ci['hi']*100).'%',0,0,'L');
            }
            if($thin && !$showCI){
                $this->SetFont('Arial','',7);
                $this->SetTextColor(180,186,196);
                $this->Cell(20,$barH,'low n',0,0,'L');
            }
            $i++;
        }
        $this->UnlockPage();
        return $y + $i*($barH+$gap);
    }

    /* ---------- MULTI-SEGMENT STACK (lifecycle composition) ---------- */

    function LifecycleBar($x,$y,$w,$data,$colors,$barH=7,$gap=3.6,$labelW=28){
        $this->LockPage();
        $track = $w - $labelW - 34;
        $i=0;
        foreach($data as $label => $segs){
            $tot = array_sum($segs);
            if($tot<=0){ $i++; continue; }
            $cy = $y + $i*($barH+$gap);
            $this->SetFont('Arial','',8);
            $this->SetTextColor(60,68,82);
            $this->SetXY($x,$cy);
            $this->Cell($labelW,$barH,$label,0,0,'L');

            $cx = $x+$labelW; $s=0;
            foreach($segs as $k => $v){
                $sw = ($v/$tot)*$track;
                list($r,$g,$b) = rgb($colors[$s % count($colors)]);
                $this->SetFillColor($r,$g,$b);
                if($sw>0.3) $this->Rect($cx,$cy+0.6,$sw,$barH-1.2,'F');
                $cx += $sw; $s++;
            }

            // formation rate = everything that wasn't "no setup" (last segment)
            $vals = array_values($segs);
            $noSetup = end($vals);
            $formed = $tot - $noSetup;
            $fp = round($formed/$tot*100);
            $this->SetXY($x+$labelW+$track+2,$cy);
            $this->SetFont('Arial','B',8);
            if($fp>=60) { list($r,$g,$b)=rgb(C_GREEN); }
            elseif($fp<35){ list($r,$g,$b)=rgb(C_RED); }
            else { list($r,$g,$b)=array(60,68,82); }
            $this->SetTextColor($r,$g,$b);
            $this->Cell(32,$barH,$fp.'% formed  (n='.$tot.')',0,0,'L');
            $i++;
        }
        $this->UnlockPage();
        return $y + $i*($barH+$gap);
    }

    /* ---------- VERTICAL COLUMNS ---------- */

    function ColumnChart($x,$y,$w,$h,$data,$col){
        $this->LockPage();
        list($r,$g,$b) = rgb($col);
        $n = count($data);
        if($n==0){ $this->UnlockPage(); return $y+$h; }
        $max = max(array_values($data)); if($max<=0) $max=1;
        $cw = $w/$n;

        $this->SetDrawColor(232,236,243);
        for($gl=1; $gl<=3; $gl++){
            $gy = $y + $h - ($h-8)*($gl/3);
            $this->Line($x,$gy,$x+$w,$gy);
            $this->SetFont('Arial','',5.5);
            $this->SetTextColor(170,176,188);
            $this->SetXY($x-7,$gy-2);
            $this->Cell(6,4,round($max*$gl/3),0,0,'R');
        }
        $this->SetDrawColor(190,196,208);
        $this->Line($x,$y+$h,$x+$w,$y+$h);

        $i=0;
        foreach($data as $label => $val){
            $bh = ($val/$max)*($h-8);
            $bx = $x + $i*$cw + $cw*0.18;
            $bwid = $cw*0.64;
            $this->SetFillColor($r,$g,$b);
            if($bh>0.3) $this->Rect($bx,$y+$h-$bh,$bwid,$bh,'F');
            if($val>0){
                $this->SetFont('Arial','B',6);
                $this->SetTextColor(45,52,66);
                $this->SetXY($bx-2,$y+$h-$bh-4.2);
                $this->Cell($bwid+4,3.5,$val,0,0,'C');
            }
            $this->SetFont('Arial','',6.5);
            $this->SetTextColor(110,118,132);
            $this->SetXY($bx-2,$y+$h+1.5);
            $this->Cell($bwid+4,3.5,$label,0,0,'C');
            $i++;
        }
        $this->UnlockPage();
        return $y+$h+7;
    }

    /* ---------- LINE CHART (percentage series) ---------- */

    function LineChart($x,$y,$w,$h,$data,$col,$benchmark=50){
        $this->LockPage();
        list($r,$g,$b) = rgb($col);
        $n = count($data);
        if($n==0){ $this->UnlockPage(); return $y+$h; }

        $this->SetFont('Arial','',5.5);
        foreach(array(0,25,50,75,100) as $lv){
            $gy = $y + $h - ($lv/100)*$h;
            $this->SetDrawColor(234,238,245);
            $this->Line($x,$gy,$x+$w,$gy);
            $this->SetTextColor(170,176,188);
            $this->SetXY($x-8,$gy-2);
            $this->Cell(7,4,$lv.'%',0,0,'R');
        }
        // break-even line
        $by = $y + $h - ($benchmark/100)*$h;
        list($kr,$kg,$kb) = rgb(C_RED);
        $this->SetDrawColor($kr,$kg,$kb);
        $this->SetLineWidth(0.4);
        $this->Line($x,$by,$x+$w,$by);
        $this->SetLineWidth(0.2);
        $this->SetFont('Arial','B',5.5);
        $this->SetTextColor($kr,$kg,$kb);
        $this->SetXY($x+$w+1,$by-2);
        $this->Cell(16,4,'B/E '.round($benchmark,1).'%',0,0,'L');

        $keys = array_keys($data);
        $step = $n>1 ? $w/($n-1) : 0;
        $pts = array();
        foreach(array_values($data) as $i=>$v){
            $pts[] = array($x + $i*$step, $y + $h - (max(0,min(100,$v))/100)*$h);
        }

        $this->SetDrawColor($r,$g,$b);
        $this->SetLineWidth(0.7);
        for($i=1;$i<$n;$i++){
            $this->Line($pts[$i-1][0],$pts[$i-1][1],$pts[$i][0],$pts[$i][1]);
        }
        $this->SetLineWidth(0.2);

        $vals = array_values($data);
        foreach($pts as $i=>$p){
            $this->SetFillColor($r,$g,$b);
            $this->Circle($p[0],$p[1],1.5,'F');
            $this->SetFont('Arial','B',5.5);
            $this->SetTextColor(45,52,66);
            $this->SetXY($p[0]-6,$p[1]-5.2);
            $this->Cell(12,3.5,round($vals[$i]).'%',0,0,'C');
            $this->SetFont('Arial','',5.5);
            $this->SetTextColor(120,128,142);
            $this->SetXY($p[0]-8,$y+$h+1.5);
            $this->Cell(16,3.5,$keys[$i],0,0,'C');
        }
        $this->UnlockPage();
        return $y+$h+7;
    }

    /* ---------- EQUITY CURVE (cumulative R, signed) ---------- */

    function EquityCurve($x,$y,$w,$h,$series,$peakIdx=-1,$troughIdx=-1){
        $this->LockPage();
        $n = count($series);
        if($n < 2){ $this->UnlockPage(); return $y+$h; }

        $max = max(max($series), 0.5);
        $min = min(min($series), -0.5);
        $range = $max - $min; if($range<=0) $range = 1;

        $toY = function($v) use ($y,$h,$max,$range){ return $y + ($max-$v)/$range*$h; };
        $zeroY = $toY(0);

        // background + gridlines
        $this->SetFillColor(250,251,253);
        $this->Rect($x,$y,$w,$h,'F');
        $this->SetDrawColor(233,237,244);
        for($gl=0;$gl<=4;$gl++){
            $gy = $y + $h*$gl/4;
            $this->Line($x,$gy,$x+$w,$gy);
            $val = $max - $range*$gl/4;
            $this->SetFont('Arial','',5.5);
            $this->SetTextColor(170,176,188);
            $this->SetXY($x-9,$gy-2);
            $this->Cell(8,4,sprintf('%+.1fu',$val),0,0,'R');
        }
        // zero baseline
        $this->SetDrawColor(150,158,172);
        $this->SetLineWidth(0.4);
        $this->Line($x,$zeroY,$x+$w,$zeroY);
        $this->SetLineWidth(0.2);

        $step = $w/($n-1);

        // filled area under curve (drawn as thin vertical bars for FPDF simplicity)
        list($gr,$gg,$gb) = rgb(C_GREEN);
        list($rr,$rg,$rb) = rgb(C_RED);
        for($i=0;$i<$n;$i++){
            $px = $x + $i*$step;
            $py = $toY($series[$i]);
            if($series[$i] >= 0){ $this->SetFillColor(min(255,$gr+150),min(255,$gg+70),min(255,$gb+110)); }
            else { $this->SetFillColor(min(255,$rr+25),min(255,$rg+150),min(255,$rb+150)); }
            $bh = abs($py-$zeroY);
            if($bh>0.2) $this->Rect($px-$step/2, min($py,$zeroY), max($step,0.4), $bh, 'F');
        }

        // curve line
        list($br,$bg,$bb) = rgb(C_NAVY);
        $this->SetDrawColor($br,$bg,$bb);
        $this->SetLineWidth(0.7);
        for($i=1;$i<$n;$i++){
            $this->Line($x+($i-1)*$step, $toY($series[$i-1]), $x+$i*$step, $toY($series[$i]));
        }
        $this->SetLineWidth(0.2);

        // peak / trough markers
        if($peakIdx >= 0 && $peakIdx < $n){
            $this->SetFillColor($gr,$gg,$gb);
            $this->Circle($x+$peakIdx*$step, $toY($series[$peakIdx]), 1.6,'F');
        }
        if($troughIdx >= 0 && $troughIdx < $n){
            $this->SetFillColor($rr,$rg,$rb);
            $this->Circle($x+$troughIdx*$step, $toY($series[$troughIdx]), 1.6,'F');
        }

        // final value badge
        $fin = end($series);
        $fx = $x+$w; $fy = $toY($fin);
        if($fin>=0){ list($cr,$cg,$cb)=rgb(C_GREEN); } else { list($cr,$cg,$cb)=rgb(C_RED); }
        $this->SetFillColor($cr,$cg,$cb);
        $this->Rect($fx+1,$fy-3,15,6,'F');
        $this->SetXY($fx+1,$fy-3);
        $this->SetFont('Arial','B',7);
        $this->SetTextColor(255,255,255);
        $this->Cell(15,6,sprintf('%+.1fu',$fin),0,0,'C');

        $this->SetFont('Arial','',6.5);
        $this->SetTextColor(140,148,162);
        $this->SetXY($x,$y+$h+1.5);
        $this->Cell($w,4,'Trade sequence (resolved signals only, in chronological order)',0,0,'C');

        $this->UnlockPage();
        return $y+$h+8;
    }

    /* ---------- HEATMAP ---------- */

    function Heatmap($x,$y,$cellW,$cellH,$rowLabels,$colLabels,$matrix,$labelW=18){
        $this->LockPage();

        // column headers
        $this->SetFont('Arial','B',6.2);
        $this->SetTextColor(120,128,142);
        $c=0;
        foreach($colLabels as $cl){
            $this->SetXY($x+$labelW+$c*$cellW, $y-4.5);
            $this->Cell($cellW,4,$cl,0,0,'C');
            $c++;
        }

        $r=0;
        foreach($rowLabels as $rk => $rl){
            $this->SetFont('Arial','B',6.8);
            $this->SetTextColor(70,78,92);
            $this->SetXY($x,$y+$r*$cellH);
            $this->Cell($labelW,$cellH,$rl,0,0,'L');
            $c=0;
            foreach($colLabels as $ck => $cl){
                $cell = isset($matrix[$rk][$ck]) ? $matrix[$rk][$ck] : array('n'=>0,'w'=>0,'l'=>0);
                $cx = $x+$labelW+$c*$cellW; $cy = $y+$r*$cellH;
                $res = $cell['w']+$cell['l'];
                if($cell['n'] == 0){
                    $this->SetFillColor(247,248,251);
                } elseif($res == 0){
                    $this->SetFillColor(233,236,242);
                } else {
                    $p = $cell['w']/$res;
                    // red -> amber -> green
                    if($p < 0.5){
                        $t = $p/0.5;
                        $R = 224 + (247-224)*$t; $G = 67 + (181-67)*$t; $B = 79 + (56-79)*$t;
                    } else {
                        $t = ($p-0.5)/0.5;
                        $R = 247 + (34-247)*$t; $G = 181 + (167-181)*$t; $B = 56 + (110-56)*$t;
                    }
                    // fade toward white when sample is tiny
                    $conf = min(1, $res/4);
                    $R = 255 + ($R-255)*$conf; $G = 255 + ($G-255)*$conf; $B = 255 + ($B-255)*$conf;
                    $this->SetFillColor((int)$R,(int)$G,(int)$B);
                }
                $this->Rect($cx+0.4,$cy+0.4,$cellW-0.8,$cellH-0.8,'F');
                if($cell['n'] > 0){
                    $this->SetFont('Arial','B',6);
                    $this->SetTextColor($res>0 ? 255 : 130, $res>0 ? 255 : 138, $res>0 ? 255 : 150);
                    if($res==0) $this->SetTextColor(140,148,160);
                    $this->SetXY($cx,$cy+0.3);
                    $this->Cell($cellW,$cellH-0.6,$cell['n'],0,0,'C');
                }
                $c++;
            }
            $r++;
        }
        $this->UnlockPage();
        return $y + count($rowLabels)*$cellH + 2;
    }

    function HeatScale($x,$y,$w){
        $steps = 40;
        for($i=0;$i<$steps;$i++){
            $p = $i/($steps-1);
            if($p < 0.5){ $t=$p/0.5; $R=224+(247-224)*$t; $G=67+(181-67)*$t; $B=79+(56-79)*$t; }
            else { $t=($p-0.5)/0.5; $R=247+(34-247)*$t; $G=181+(167-181)*$t; $B=56+(110-56)*$t; }
            $this->SetFillColor((int)$R,(int)$G,(int)$B);
            $this->Rect($x+$i*($w/$steps),$y,$w/$steps+0.2,2.6,'F');
        }
        $this->SetFont('Arial','',6);
        $this->SetTextColor(140,148,162);
        $this->SetXY($x-10,$y-0.7);   $this->Cell(9,4,'0%',0,0,'R');
        $this->SetXY($x+$w+1,$y-0.7); $this->Cell(12,4,'100% win',0,0,'L');
        return $y+5;
    }

    /* ---------- DIRECTION BIAS (paired bars) ---------- */

    function BiasChart($x,$y,$w,$data,$labelW=28,$rowH=11){
        $this->LockPage();
        list($gr,$gg,$gb) = rgb(C_BLUE);
        list($ar,$ag,$ab) = rgb(C_AMBER);
        $track = ($w - $labelW - 30)/2 - 3;
        $i=0;
        foreach($data as $label => $d){
            $cy = $y + $i*$rowH;
            $this->SetFont('Arial','B',8);
            $this->SetTextColor(55,62,76);
            $this->SetXY($x,$cy+1.5);
            $this->Cell($labelW,6,$label,0,0,'L');

            $sets = array(
                array('CALL', $d['call'], array($gr,$gg,$gb)),
                array('PUT',  $d['put'],  array($ar,$ag,$ab))
            );
            $k=0;
            foreach($sets as $s){
                $tot = $s[1]['w']+$s[1]['l'];
                $bx = $x+$labelW + $k*($track+6);
                $this->SetFillColor(238,241,247);
                $this->Rect($bx,$cy+2,$track,5,'F');
                if($tot>0){
                    $p = $s[1]['w']/$tot;
                    $this->SetFillColor($s[2][0],$s[2][1],$s[2][2]);
                    if($p*$track > 0.3) $this->Rect($bx,$cy+2,$p*$track,5,'F');
                }
                $this->SetFont('Arial','',6.5);
                $this->SetTextColor(120,128,142);
                $this->SetXY($bx,$cy-2.2);
                $this->Cell($track,4,$s[0].($tot>0 ? '  '.round(($s[1]['w']/$tot)*100).'%  ('.$tot.')' : '  no data'),0,0,'L');
                $k++;
            }
            $i++;
        }
        $this->UnlockPage();
        return $y + $i*$rowH;
    }

    /* ---------- SIGNED BAR (net units, +/- around zero) ---------- */

    function SignedBar($x,$y,$w,$data,$barH=6.5,$gap=3.4,$labelW=28){
        $this->LockPage();
        $vals = array_values($data);
        $maxAbs = 0;
        foreach($vals as $v){ $maxAbs = max($maxAbs, abs($v)); }
        if($maxAbs <= 0) $maxAbs = 1;
        $track = $w - $labelW - 22;
        $zx = $x + $labelW + $track/2;
        list($gr,$gg,$gb) = rgb(C_GREEN);
        list($rr,$rg,$rb) = rgb(C_RED);

        $totH = count($data)*($barH+$gap);
        $this->SetDrawColor(205,211,222);
        $this->SetLineWidth(0.4);
        $this->Line($zx,$y-1,$zx,$y+$totH-$gap+1);
        $this->SetLineWidth(0.2);

        $i=0;
        foreach($data as $label => $v){
            $cy = $y + $i*($barH+$gap);
            $this->SetFont('Arial','',8);
            $this->SetTextColor(60,68,82);
            $this->SetXY($x,$cy);
            $this->Cell($labelW,$barH,$label,0,0,'L');

            $bw = (abs($v)/$maxAbs)*($track/2);
            if($v >= 0){
                $this->SetFillColor($gr,$gg,$gb);
                if($bw>0.3) $this->Rect($zx,$cy+0.6,$bw,$barH-1.2,'F');
                $this->SetTextColor($gr,$gg,$gb);
            } else {
                $this->SetFillColor($rr,$rg,$rb);
                if($bw>0.3) $this->Rect($zx-$bw,$cy+0.6,$bw,$barH-1.2,'F');
                $this->SetTextColor($rr,$rg,$rb);
            }
            $this->SetXY($x+$labelW+$track+1,$cy);
            $this->SetFont('Arial','B',8);
            $this->Cell(20,$barH,sprintf('%+.2f u',$v),0,0,'R');
            $i++;
        }
        $this->UnlockPage();
        return $y + $i*($barH+$gap);
    }

    /* ---------- PAYOUT SENSITIVITY TABLE ---------- */

    function SensitivityTable($x,$y,$w,$payouts,$wins,$losses,$actualPayout){
        $this->LockPage();
        $n = count($payouts);
        $cw = $w/($n+1);
        $rowsLbl = array('Payout','Break-even WR','Net (units)','ROI');
        $resolved = $wins+$losses;
        $p = $resolved>0 ? $wins/$resolved : 0;

        list($nr,$ng,$nb) = rgb(C_NAVY);
        for($r=0;$r<4;$r++){
            $cy = $y + $r*8;
            $this->SetFont('Arial','B',7.5);
            $this->SetFillColor($r==0?$nr:244, $r==0?$ng:247, $r==0?$nb:252);
            $this->Rect($x,$cy,$cw,8,'F');
            $this->SetTextColor($r==0?255:70, $r==0?255:78, $r==0?255:92);
            $this->SetXY($x+2,$cy);
            $this->Cell($cw-2,8,$rowsLbl[$r],0,0,'L');

            $c=0;
            foreach($payouts as $po){
                $cx = $x+$cw+$c*$cw;
                $isActual = (abs($po-$actualPayout) < 0.001);
                $be  = 1/(1+$po);
                $net = $wins*$po - $losses;
                $roi = $resolved>0 ? ($net/$resolved)*100 : 0;

                if($r==0){ $this->SetFillColor($nr,$ng,$nb); }
                elseif($isActual){ $this->SetFillColor(233,240,252); }
                else { $this->SetFillColor(250,251,253); }
                $this->Rect($cx,$cy,$cw,8,'F');

                if($isActual && $r>0){
                    list($br2,$bg2,$bb2) = rgb(C_BLUE);
                    $this->SetDrawColor($br2,$bg2,$bb2);
                    $this->SetLineWidth(0.4);
                    $this->Rect($cx,$cy,$cw,8,'D');
                    $this->SetLineWidth(0.2);
                }

                $this->SetXY($cx,$cy);
                $this->SetFont('Arial','B',7.5);
                if($r==0){
                    $this->SetTextColor(255,255,255);
                    $txt = round($po*100).'%';
                } elseif($r==1){
                    $this->SetTextColor(70,78,92);
                    $txt = round($be*100,1).'%';
                } elseif($r==2){
                    if($net>=0){ list($cr,$cg,$cb)=rgb(C_GREEN); } else { list($cr,$cg,$cb)=rgb(C_RED); }
                    $this->SetTextColor($cr,$cg,$cb);
                    $txt = sprintf('%+.1f',$net);
                } else {
                    if($roi>=0){ list($cr,$cg,$cb)=rgb(C_GREEN); } else { list($cr,$cg,$cb)=rgb(C_RED); }
                    $this->SetTextColor($cr,$cg,$cb);
                    $txt = sprintf('%+.1f%%',$roi);
                }
                $this->Cell($cw,8,$txt,0,0,'C');
                $c++;
            }
        }
        $this->UnlockPage();
        return $y + 32;
    }

    /* ---------- COMPOUNDING TABLES ---------- */

    // Generic table renderer: $cols = array(array(label,width,align), ...)
    // $rows = array of array(array(text, colorConst|null, bold), ...)
    function DataTable($x,$y,$cols,$rows,$rowH=7,$headH=8){
        $this->LockPage();
        list($nr,$ng,$nb) = rgb(C_NAVY);
        $this->SetXY($x,$y);
        $this->SetFont('Arial','B',7.5);
        $this->SetFillColor($nr,$ng,$nb);
        $this->SetDrawColor($nr,$ng,$nb);
        $this->SetTextColor(255,255,255);
        foreach($cols as $c) $this->Cell($c[1],$headH,$c[0],1,0,isset($c[2])?$c[2]:'C',true);
        $this->Ln();
        $this->SetDrawColor(224,229,238);
        $fill=false;
        foreach($rows as $row){
            $this->SetX($x);
            $bgv = $fill?247:255;
            foreach($row as $i => $cell){
                $txt = $cell[0];
                $col = isset($cell[1]) ? $cell[1] : null;
                $bold= isset($cell[2]) ? $cell[2] : false;
                $hl  = isset($cell[3]) ? $cell[3] : null;   // per-cell background
                if($hl !== null){ list($hr,$hg,$hb) = rgb($hl); $this->SetFillColor($hr,$hg,$hb); }
                else $this->SetFillColor($bgv,$bgv==255?255:250,$bgv==255?255:253);
                if($col !== null){ list($cr,$cg,$cb) = rgb($col); $this->SetTextColor($cr,$cg,$cb); }
                else $this->SetTextColor(45,52,66);
                $this->SetFont('Arial',$bold?'B':'',7.5);
                $this->Cell($cols[$i][1],$rowH,$txt,1,0,isset($cols[$i][2])?$cols[$i][2]:'C',true);
            }
            $this->Ln();
            $fill=!$fill;
        }
        $this->UnlockPage();
        return $this->GetY();
    }

    // Observed vs theoretical loss-streak counts, side by side
    function StreakChart($x,$y,$w,$h,$obs,$exp,$maxK){
        $this->LockPage();
        $all = array_merge(array_values($obs),array_values($exp));
        $max = count($all) ? max($all) : 1;
        if($max<=0) $max=1;
        $n = $maxK;
        $grp = $w/$n;
        $bw = $grp*0.32;

        $this->SetDrawColor(232,236,243);
        for($gl=1;$gl<=3;$gl++){
            $gy = $y + $h - ($h-8)*($gl/3);
            $this->Line($x,$gy,$x+$w,$gy);
            $this->SetFont('Arial','',5.5);
            $this->SetTextColor(170,176,188);
            $this->SetXY($x-8,$gy-2);
            $this->Cell(7,4,round($max*$gl/3,1),0,0,'R');
        }
        $this->SetDrawColor(190,196,208);
        $this->Line($x,$y+$h,$x+$w,$y+$h);

        list($br,$bg,$bb) = rgb(C_BLUE);
        list($gr2,$gg2,$gb2) = rgb(C_GREY);
        for($k=1;$k<=$maxK;$k++){
            $gx = $x + ($k-1)*$grp;
            $o = isset($obs[$k])?$obs[$k]:0;
            $e = isset($exp[$k])?$exp[$k]:0;

            $oh = ($o/$max)*($h-8);
            $eh = ($e/$max)*($h-8);

            $this->SetFillColor($gr2,$gg2,$gb2);
            if($eh>0.3) $this->Rect($gx+$grp*0.16,$y+$h-$eh,$bw,$eh,'F');
            $this->SetFillColor($br,$bg,$bb);
            if($oh>0.3) $this->Rect($gx+$grp*0.52,$y+$h-$oh,$bw,$oh,'F');

            $this->SetFont('Arial','B',5.5);
            $this->SetTextColor(120,128,142);
            $this->SetXY($gx+$grp*0.16-1,$y+$h-$eh-4);
            $this->Cell($bw+2,3.5,round($e,1),0,0,'C');
            $this->SetTextColor(45,52,66);
            $this->SetXY($gx+$grp*0.52-1,$y+$h-$oh-4);
            $this->Cell($bw+2,3.5,$o,0,0,'C');

            $this->SetFont('Arial','',6.5);
            $this->SetTextColor(110,118,132);
            $this->SetXY($gx,$y+$h+1.5);
            $lbl = ($k==$maxK) ? $k.'+ losses' : $k.' loss'.($k>1?'es':'');
            $this->Cell($grp,3.5,$lbl,0,0,'C');
        }
        $this->UnlockPage();
        return $y+$h+7;
    }

    /* ---------- MINI INLINE BAR ---------- */

    function MiniBar($x,$y,$w,$h,$pct,$col){
        list($r,$g,$b) = rgb($col);
        $this->SetFillColor(233,237,244);
        $this->Rect($x,$y,$w,$h,'F');
        $this->SetFillColor($r,$g,$b);
        if($pct>0) $this->Rect($x,$y,$w*($pct/100),$h,'F');
    }

    /* ---------- CALLOUT BOX ---------- */

    function Callout($title,$lines,$accent=C_BLUE,$bgR=240,$bgG=245,$bgB=253){
        $h = 7 + count($lines)*4.6;
        $this->NeedSpace($h+4);
        $y = $this->GetY();
        $this->SetFillColor($bgR,$bgG,$bgB);
        $this->Rect(10,$y,190,$h,'F');
        list($r,$g,$b) = rgb($accent);
        $this->SetFillColor($r,$g,$b);
        $this->Rect(10,$y,2,$h,'F');
        $this->SetXY(15,$y+2);
        $this->SetFont('Arial','B',8.5);
        $this->SetTextColor($r,$g,$b);
        $this->Cell(0,5,$title,0,2,'L');
        $this->SetFont('Arial','',8);
        $this->SetTextColor(70,78,92);
        foreach($lines as $ln){ $this->Cell(0,4.6,$ln,0,2,'L'); }
        $this->SetXY(10,$y+$h+4);
        return $this->GetY();
    }

    /* ---------- SIMPLE METRIC GRID ---------- */

    function MetricGrid($x,$y,$w,$items,$cols=4,$rowH=15){
        $cw = $w/$cols;
        $i=0;
        foreach($items as $label => $val){
            $cx = $x + ($i % $cols)*$cw;
            $cy = $y + floor($i/$cols)*$rowH;
            $this->SetFillColor(248,250,253);
            $this->Rect($cx+1,$cy,$cw-2,$rowH-2,'F');
            list($nr,$ng,$nb) = rgb(C_NAVY);
            $this->SetFillColor($nr,$ng,$nb);
            $this->Rect($cx+1,$cy,1.4,$rowH-2,'F');
            $this->SetXY($cx+4,$cy+1.6);
            $this->SetFont('Arial','B',11);
            $this->SetTextColor(30,38,54);
            $this->Cell($cw-8,6,$val,0,2,'L');
            $this->SetFont('Arial','',6.6);
            $this->SetTextColor(125,133,147);
            $this->Cell($cw-8,4,$label,0,0,'L');
            $i++;
        }
        return $y + ceil($i/$cols)*$rowH;
    }
}

/* ============================================================
   DOMAIN HELPERS
   ============================================================ */

function getForexSession($time){
    $time = date('H:i', strtotime($time));
    $sessions = array(
        "Sydney"   => array("02:30", "11:30"),
        "Tokyo"    => array("05:30", "14:30"),
        "London"   => array("12:30", "21:30"),
        "New York" => array("17:30", "02:30"),
    );
    $active = array();
    foreach($sessions as $name => $win){
        $start = $win[0]; $end = $win[1];
        if ($start < $end){
            if ($time >= $start && $time <= $end) $active[] = $name;
        } else {
            if ($time >= $start || $time <= $end) $active[] = $name;
        }
    }
    return count($active) > 0 ? implode("+", $active) : "None";
}

function shortSession($s){
    $map = array('Sydney'=>'SYD','Tokyo'=>'TYO','London'=>'LDN','New York'=>'NY','None'=>'--');
    $parts = explode('+', $s);
    foreach($parts as &$p){ $p = isset($map[$p]) ? $map[$p] : substr($p,0,3); }
    unset($p);
    return implode('+', $parts);
}

function classifyResult($result){
    $r = strtolower(trim((string)$result));
    if($r === '') return 'pending';
    if(strpos($r,'setup')!==false || strpos($r,'not_formed')!==false || strpos($r,'not formed')!==false) return 'notformed';
    if(strpos($r,'win')!==false || strpos($r,'tp')!==false || strpos($r,'profit')!==false || strpos($r,'success')!==false) return 'win';
    if(strpos($r,'loss')!==false || strpos($r,'lose')!==false || strpos($r,'fail')!==false || $r==='sl') return 'loss';
    if(strpos($r,'pending')!==false || strpos($r,'open')!==false || strpos($r,'running')!==false || strpos($r,'active')!==false) return 'pending';
    return 'notformed';
}

// Wilson score interval - honest bounds for small samples
function wilson($w,$n,$z=1.96){
    if($n <= 0) return array('lo'=>0,'hi'=>0,'p'=>0);
    $p = $w/$n;
    $den = 1 + ($z*$z)/$n;
    $centre = ($p + ($z*$z)/(2*$n))/$den;
    $margin = ($z*sqrt(($p*(1-$p))/$n + ($z*$z)/(4*$n*$n)))/$den;
    return array('lo'=>max(0,$centre-$margin), 'hi'=>min(1,$centre+$margin), 'p'=>$p);
}

function splitPair($pair){
    $p = strtoupper(preg_replace('/[^A-Za-z]/','',$pair));
    if(strlen($p) === 6) return array(substr($p,0,3), substr($p,3,3));
    return array(null,null);
}

function fmtDur($mins){
    if($mins === null) return '-';
    if($mins < 60) return round($mins).'m';
    if($mins < 1440) return round($mins/60,1).'h';
    return round($mins/1440,1).'d';
}

// Aggregate a result-set into headline counts (used for the previous period)
function quickAggregate($res){
    $out = array('total'=>0,'win'=>0,'loss'=>0,'pending'=>0,'notformed'=>0);
    if(!$res) return $out;
    while($r = $res->fetch_assoc()){
        $out['total']++;
        $c = classifyResult($r['trade_result']);
        $out[$c]++;
    }
    return $out;
}


/* ============================================================
   COMPOUNDING MATHS
   All formulas verified numerically before implementation.
   Core invariant: expectancy per unit staked is p*b - (1-p)
   for EVERY staking style. Compounding changes exposure,
   variance and capital requirement - never the edge.
   ============================================================ */

// 2x martingale: double the last stake after every loss (the common
// real-world version). At a payout below 100% this does NOT fully recover
// prior losses - winning at step k nets stake_k*(1+b) minus cumulative
// staked, which shrinks each step and eventually goes negative. That
// break point is the single most important thing this ladder reveals.
function martingaleLadder($b,$maxN){
    $stakes = array(); $cum = array(); $net = array(); $c = 0;
    $breakStep = null;
    for($k=1;$k<=$maxN;$k++){
        $st = pow(2,$k-1);          // 1, 2, 4, 8, ...
        $stakes[$k] = $st;
        $c += $st;
        $cum[$k] = $c;
        $net[$k] = $st*(1+$b) - $c; // net profit if this step wins
        if($net[$k] <= 0 && $breakStep === null) $breakStep = $k;
    }
    return array('ratio'=>2.0,'stakes'=>$stakes,'cum'=>$cum,'net'=>$net,'breakStep'=>$breakStep);
}

// Expected trades consumed by one martingale cycle at depth N
function martingaleCycleTrades($p,$N){
    $q = 1-$p; $E = 0;
    for($k=1;$k<=$N;$k++) $E += $k*pow($q,$k-1)*$p;
    $E += $N*pow($q,$N);
    return $E>0 ? $E : 1;
}

// Probability the ladder busts at least once within T trades
function martingaleRuin($p,$N,$T){
    $q = 1-$p;
    $bust = pow($q,$N);
    $cycles = $T / martingaleCycleTrades($p,$N);
    return 1 - pow(1-$bust, $cycles);
}

// Parlay (whole-balance): let the entire balance ride. Balance grows by
// (1+b) per win, so after N wins it is (1+b)^N. A single loss at ANY step
// returns the whole cycle to zero - there is no base-stake protection.
// EV of committing to ride exactly N then cashing out, per 1 unit risked:
//   p^N * (1+b)^N  -  1
function parlayStats($p,$b,$N){
    $x = $p*(1+$b);                 // governing quantity
    $pComplete = pow($p,$N);
    $balanceIfWin = pow(1+$b,$N);   // 1.8, 3.24, 5.832, ... at b=0.8
    $evCycle = $pComplete*$balanceIfWin - 1;
    // expected trades placed before the cycle ends (win N in a row, or lose once)
    $et = 0;
    for($k=1;$k<$N;$k++) $et += $k*pow($p,$k-1)*(1-$p);
    $et += $N*pow($p,$N-1);
    if($et <= 0) $et = 1;
    return array(
        'x'        => $x,
        'evCycle'  => $evCycle,
        'trades'   => $et,
        'evTrade'  => $evCycle/$et,
        'pComplete'=> $pComplete,
        'payoff'   => $balanceIfWin - 1,   // profit multiple if cycle completes
        'balance'  => $balanceIfWin,
    );
}

// Wald-Wolfowitz runs test. Z < -1.96 means outcomes cluster
// (fewer runs than chance) - which breaks the independence
// assumption every compounding formula relies on.
function runsTest($seq){
    $n = count($seq);
    if($n < 8) return null;
    $n1 = 0; foreach($seq as $v) if($v) $n1++;
    $n2 = $n - $n1;
    if($n1 == 0 || $n2 == 0) return null;
    $R = 1;
    for($i=1;$i<$n;$i++) if($seq[$i] !== $seq[$i-1]) $R++;
    $mu  = (2*$n1*$n2)/$n + 1;
    $var = (2*$n1*$n2*(2*$n1*$n2 - $n))/(($n*$n)*($n-1));
    if($var <= 0) return null;
    $z = ($R - $mu)/sqrt($var);
    return array('R'=>$R,'exp'=>$mu,'z'=>$z,
        'verdict'=> ($z < -1.96 ? 'CLUSTERED' : ($z > 1.96 ? 'ALTERNATING' : 'INDEPENDENT')));
}

// Observed loss-run lengths vs the Bernoulli expectation
function streakDist($seq,$p,$maxK=5){
    $obs = array(); $exp = array();
    for($k=1;$k<=$maxK;$k++){ $obs[$k]=0; $exp[$k]=0; }
    $n = count($seq); $q = 1-$p;
    $run = 0;
    foreach($seq as $v){
        if(!$v){ $run++; }
        else { if($run>0){ $kk=min($run,$maxK); $obs[$kk]++; } $run=0; }
    }
    if($run>0){ $kk=min($run,$maxK); $obs[$kk]++; }
    for($k=1;$k<$maxK;$k++)  $exp[$k] = $n*$p*$p*pow($q,$k);
    $exp[$maxK] = $n*$p*pow($q,$maxK);   // tail: k or longer
    return array('obs'=>$obs,'exp'=>$exp);
}

// Sample adequacy gate
function sampleVerdict($n){
    if($n < 30) return array('INSUFFICIENT', C_RED);
    if($n < 80) return array('PROVISIONAL',  C_AMBER);
    return array('MEASURABLE', C_GREEN);
}

// Resolved trades needed for a Wilson half-width of $hw
function tradesNeeded($p,$hw=0.07){
    if($p<=0 || $p>=1) $p = 0.5;
    return (int)ceil(3.8416*$p*(1-$p)/($hw*$hw));
}

// Monte Carlo: bankroll path over T trades, returns percentiles + ruin rate
function mcSim($p,$b,$style,$N,$runs,$T,$bankroll){
    $lad = martingaleLadder($b,$N);
    $finals = array(); $ruined = 0;
    $scale = mt_getrandmax();
    for($r=0;$r<$runs;$r++){
        $bk = $bankroll; $step = 1; $parlayStake = 1.0; $busted = false;
        for($t=0;$t<$T;$t++){
            if($style==='flat'){
                $stake = 1.0;
                if($stake > $bk){ $busted = true; break; }
                $win = (mt_rand()/$scale) < $p;
                $bk += $win ? $stake*$b : -$stake;
            }
            elseif($style==='martingale'){
                $stake = pow(2,$step-1);            // double last stake
                if($stake > $bk){ $busted = true; break; }
                $win = (mt_rand()/$scale) < $p;
                if($win){ $bk += $stake*$b; $step = 1; }
                else    { $bk -= $stake; $step = ($step >= $N) ? 1 : $step+1; }
            }
            else { // parlay: whole current cycle balance rides
                if($step === 1) $parlayStake = 1.0;  // fresh cycle risks 1 unit
                $atRisk = $parlayStake;
                if($atRisk > $bk){ $busted = true; break; }
                $win = (mt_rand()/$scale) < $p;
                if($win){
                    $parlayStake = $parlayStake*(1+$b);   // whole balance rides
                    if($step >= $N){ $bk += $parlayStake - 1.0; $parlayStake = 1.0; $step = 1; }
                    else { $step++; }
                } else {
                    $bk -= 1.0;                 // lose the 1 unit committed to this cycle
                    $parlayStake = 1.0; $step = 1;
                }
            }
            if($bk <= 0){ $busted = true; break; }
        }
        if($busted || $bk <= 0){ $ruined++; $bk = max(0,$bk); }
        $finals[] = $bk;
    }
    sort($finals);
    $q = function($f) use ($finals){ return $finals[min(count($finals)-1,(int)floor($f*count($finals)))]; };
    return array(
        'p05'=>$q(0.05), 'p50'=>$q(0.50), 'p95'=>$q(0.95),
        'mean'=>array_sum($finals)/max(1,count($finals)),
        'ruin'=>$ruined/max(1,$runs),
    );
}

/* ============================================================
   REPORT
   ============================================================ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startDate = $_POST['start_date'] ?? '';
    $endDate   = $_POST['end_date'] ?? '';

    // Binary options economics: fixed payout on a win, full stake lost on a loss.
    $payout    = isset($_POST['payout']) ? floatval($_POST['payout'])/100 : 0.80;
    if($payout <= 0 || $payout > 5) $payout = 0.80;
    $breakeven = 1/(1+$payout);          // win rate required just to stay flat
    $stakeAmt  = isset($_POST['stake']) ? floatval($_POST['stake']) : 0;

    // Compounding analysis parameters
    $ruinMax   = isset($_POST['ruin_max']) ? floatval($_POST['ruin_max'])/100 : 0.20;
    if($ruinMax <= 0 || $ruinMax > 1) $ruinMax = 0.20;
    $horizon   = isset($_POST['horizon']) ? max(20,(int)$_POST['horizon']) : 100;
    $mcRuns    = isset($_POST['mc_runs']) ? max(200,min(20000,(int)$_POST['mc_runs'])) : 2000;
    $maxDepth  = 6;
    @set_time_limit(180);

    if ($startDate && $endDate) {
        $tzResult = $conn->query("SELECT @@session.time_zone AS session_tz");
        $tzRow    = $tzResult->fetch_assoc();
        $serverTZ = $tzRow['session_tz'] ?: 'SYSTEM';

        $start = $startDate . " 00:00:00";
        $end   = $endDate . " 23:59:59";

$sql = "
    SELECT
        raw_trade_id,
        pair_name,
        price_target, trade_result,
        trade_direction,
        updated_at,
        last_alert_time AS last_alert_ist,
        last_alert_time AS updated_ist
    FROM prediction_trade_data
    WHERE last_alert_time BETWEEN '$start' AND '$end'
    ORDER BY last_alert_time ASC, raw_trade_id ASC
";

        $res = $conn->query($sql);

        /* ---------- PREVIOUS PERIOD (same length, immediately before) ---------- */
        $spanDays  = max(1, (int)round((strtotime($endDate) - strtotime($startDate))/86400) + 1);
        $prevEnd   = date('Y-m-d', strtotime($startDate.' -1 day'));
        $prevStart = date('Y-m-d', strtotime($prevEnd.' -'.($spanDays-1).' day'));
        $pStart = $prevStart." 00:00:00"; $pEnd = $prevEnd." 23:59:59";
        $prevSql = "
    SELECT trade_result, trade_direction
    FROM prediction_trade_data
    WHERE last_alert_time BETWEEN '$pStart' AND '$pEnd'
";
        $prev = quickAggregate($conn->query($prevSql));

        if ($res && $res->num_rows > 0) {

            /* ================= AGGREGATION ================= */
            $rows = array();
            $totalTrades = $upCount = $downCount = 0;
            $winCount = $lossCount = $pendingCount = $notFormedCount = 0;

            $pairCount = array(); $sessionCount = array(); $hourCount = array();
            $pairWL = array(); $sessionWL = array();
            $pairLifecycle = array();          // pair => [win, loss, pending, nosetup]
            $pairBias = array();               // pair => call/put WL
            $dirWL = array('CALL'=>array('w'=>0,'l'=>0),'PUT'=>array('w'=>0,'l'=>0));
            $dayStats = array();
            $ccyWL = array();                  // currency => WL (any leg)
            $ccyCount = array();
            $heat = array();                   // weekday => hour => [n,w,l]
            $dowWL = array();
            $durBuckets = array(
                'Under 30 min' => array('w'=>0,'l'=>0),
                '30 min - 2 h' => array('w'=>0,'l'=>0),
                '2 h - 6 h'    => array('w'=>0,'l'=>0),
                'Over 6 h'     => array('w'=>0,'l'=>0),
            );
            $durs = array();
            $pendingAges = array();
            $equity = array(0.0); $eqLabels = array();
            $seqAll = array(); $seqPair = array();   // 1=win 0=loss, chronological
            $nowTs = time();

            $dowNames = array(1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun');

            while($row = $res->fetch_assoc()){
                $timeIST  = $row['last_alert_ist'];
                $ts       = strtotime($timeIST);
                $dir      = ($row['trade_direction']==='UP') ? 'CALL' : 'PUT';
                $sessFull = getForexSession($timeIST);
                $sess     = shortSession($sessFull);
                $hour     = date('H', $ts);
                $dow      = (int)date('N', $ts);
                $dateOnly = date('d M Y', $ts);
                $cls      = classifyResult($row['trade_result']);

                $row['_dir']=$dir; $row['_sess']=$sess; $row['_date']=$dateOnly;
                $row['_time']=date('H:i',$ts); $row['_cls']=$cls; $row['_ts']=$ts;
                $rows[] = $row;

                $totalTrades++;
                if($dir==='CALL') $upCount++; else $downCount++;

                if($cls==='win') $winCount++;
                elseif($cls==='loss') $lossCount++;
                elseif($cls==='pending') $pendingCount++;
                else $notFormedCount++;

                $p = $row['pair_name'];
                $pairCount[$p]       = (isset($pairCount[$p])?$pairCount[$p]:0)+1;
                $sessionCount[$sess] = (isset($sessionCount[$sess])?$sessionCount[$sess]:0)+1;
                $hourCount[$hour]    = (isset($hourCount[$hour])?$hourCount[$hour]:0)+1;

                if(!isset($pairWL[$p]))        $pairWL[$p]=array('w'=>0,'l'=>0);
                if(!isset($sessionWL[$sess]))  $sessionWL[$sess]=array('w'=>0,'l'=>0);
                if(!isset($pairLifecycle[$p])) $pairLifecycle[$p]=array('win'=>0,'loss'=>0,'pending'=>0,'notformed'=>0);
                if(!isset($pairBias[$p]))      $pairBias[$p]=array('call'=>array('w'=>0,'l'=>0),'put'=>array('w'=>0,'l'=>0));
                if(!isset($dowWL[$dow]))       $dowWL[$dow]=array('w'=>0,'l'=>0);
                if(!isset($heat[$dow][$hour])) $heat[$dow][$hour]=array('n'=>0,'w'=>0,'l'=>0);
                if(!isset($dayStats[$dateOnly])) $dayStats[$dateOnly]=array('t'=>0,'u'=>0,'d'=>0,'w'=>0,'l'=>0,'p'=>0,'n'=>0,'ts'=>$ts);

                $pairLifecycle[$p][$cls]++;
                $heat[$dow][$hour]['n']++;
                $dayStats[$dateOnly]['t']++;
                if($dir==='CALL') $dayStats[$dateOnly]['u']++; else $dayStats[$dateOnly]['d']++;

                list($base,$quote) = splitPair($p);
                foreach(array($base,$quote) as $ccy){
                    if($ccy === null) continue;
                    if(!isset($ccyWL[$ccy])) $ccyWL[$ccy]=array('w'=>0,'l'=>0);
                    $ccyCount[$ccy] = (isset($ccyCount[$ccy])?$ccyCount[$ccy]:0)+1;
                }

                if($cls==='win' || $cls==='loss'){
                    $isWin = ($cls==='win');
                    $k = $isWin ? 'w' : 'l';

                    $pairWL[$p][$k]++;
                    $sessionWL[$sess][$k]++;
                    $dirWL[$dir][$k]++;
                    $dayStats[$dateOnly][$k]++;
                    $dowWL[$dow][$k]++;
                    $heat[$dow][$hour][$k]++;
                    $pairBias[$p][strtolower($dir)][$k]++;
                    foreach(array($base,$quote) as $ccy){
                        if($ccy !== null) $ccyWL[$ccy][$k]++;
                    }

                    // equity curve
                    $equity[] = end($equity) + ($isWin ? $payout : -1);
                    $seqAll[] = $isWin ? 1 : 0;
                    if(!isset($seqPair[$p])) $seqPair[$p] = array();
                    $seqPair[$p][] = $isWin ? 1 : 0;
                    $eqLabels[] = $row['raw_trade_id'];

                    // time to resolution
                    if(!empty($row['updated_ist'])){
                        $mins = (strtotime($row['updated_ist']) - $ts)/60;
                        if($mins > 0 && $mins < 60*24*7){
                            $durs[] = $mins;
                            if($mins < 30)        $durBuckets['Under 30 min'][$k]++;
                            elseif($mins < 120)   $durBuckets['30 min - 2 h'][$k]++;
                            elseif($mins < 360)   $durBuckets['2 h - 6 h'][$k]++;
                            else                  $durBuckets['Over 6 h'][$k]++;
                        }
                    }
                } elseif($cls==='pending'){
                    $dayStats[$dateOnly]['p']++;
                    $pendingAges[] = ($nowTs - $ts)/3600;
                } else {
                    $dayStats[$dateOnly]['n']++;
                }
            }

            arsort($pairCount);
            arsort($sessionCount);
            $topPairs = array_slice($pairCount, 0, 10, true);

            ksort($hourCount);
            $activeHours = array_keys(array_filter($hourCount, function($v){ return $v>0; }));
            $hourSeries = array();
            if($activeHours){
                $hMin = (int)min($activeHours); $hMax = (int)max($activeHours);
                for($h=$hMin;$h<=$hMax;$h++){
                    $k2 = str_pad($h,2,'0',STR_PAD_LEFT);
                    $hourSeries[$k2] = isset($hourCount[$k2]) ? $hourCount[$k2] : 0;
                }
            }

            $resolved     = $winCount + $lossCount;
            $winRate      = $resolved>0 ? round(($winCount/$resolved)*100,1) : 0;
            $executionPct = $totalTrades>0 ? round(($resolved/$totalTrades)*100,1) : 0;
            $formationPct = $totalTrades>0 ? round((($totalTrades-$notFormedCount)/$totalTrades)*100,1) : 0;

            // Previous-period deltas
            $prevResolved = $prev['win']+$prev['loss'];
            $prevWinRate  = $prevResolved>0 ? ($prev['win']/$prevResolved)*100 : null;
            $prevExec     = $prev['total']>0 ? ($prevResolved/$prev['total'])*100 : null;
            $dTotal   = $prev['total']>0 ? ($totalTrades - $prev['total']) : null;
            $dWinRate = ($prevWinRate !== null) ? ($winRate - $prevWinRate) : null;
            $dExec    = ($prevExec !== null) ? ($executionPct - $prevExec) : null;

            // Binary options expectancy
            $p = $resolved>0 ? $winCount/$resolved : 0;
            $expPerTrade  = $resolved>0 ? ($p*$payout - (1-$p)) : 0;   // units per unit staked
            $netUnits     = $winCount*$payout - $lossCount;
            $roiPct       = $resolved>0 ? ($netUnits/$resolved)*100 : 0;
            $profitFactor = $lossCount>0 ? ($winCount*$payout)/$lossCount : ($winCount>0 ? INF : 0);
            $edgeMargin   = ($winRate/100) - $breakeven;                // in decimal, +ve = profitable
            $ciAll = wilson($winCount,$resolved);
            $ciLoProfitable = ($ciAll['lo'] > $breakeven);
            // Kelly stake fraction for a binary payout b: f = (p(1+b) - 1) / b
            $kelly = ($payout > 0) ? (($p*(1+$payout) - 1)/$payout) : 0;
            $kellyPct = max(0, $kelly)*100;

            // Streaks & drawdown from the equity series
            $maxWinStreak=$maxLossStreak=$curStreak=0; $curSign=0;
            for($i=1;$i<count($equity);$i++){
                $delta = $equity[$i]-$equity[$i-1];
                $sign = $delta>0?1:-1;
                if($sign === $curSign) $curStreak++; else { $curSign=$sign; $curStreak=1; }
                if($sign>0) $maxWinStreak = max($maxWinStreak,$curStreak);
                else        $maxLossStreak= max($maxLossStreak,$curStreak);
            }
            $currentStreak = ($curSign>0?'+':'-').$curStreak;
            $peak=-INF; $maxDD=0; $peakIdx=0; $troughIdx=0; $bestPeakIdx=0;
            foreach($equity as $i=>$v){
                if($v > $peak){ $peak=$v; $peakIdx=$i; }
                $dd = $peak - $v;
                if($dd > $maxDD){ $maxDD=$dd; $troughIdx=$i; $bestPeakIdx=$peakIdx; }
            }
            $finalR = end($equity);   // same as $netUnits, kept for the curve badge

            // Duration stats
            $avgDur = count($durs) ? array_sum($durs)/count($durs) : null;
            $medDur = null;
            if(count($durs)){ sort($durs); $medDur = $durs[(int)floor(count($durs)/2)]; }
            $stalePending = 0;
            foreach($pendingAges as $ag){ if($ag > 24) $stalePending++; }

            // Net units per pair and per session at the assumed payout
            $pairNet = array(); $sessionNet = array();
            foreach($pairWL as $pk => $d){
                if(($d['w']+$d['l'])>0) $pairNet[$pk] = $d['w']*$payout - $d['l'];
            }
            arsort($pairNet);
            foreach($sessionWL as $sk => $d){
                if(($d['w']+$d['l'])>0) $sessionNet[$sk] = $d['w']*$payout - $d['l'];
            }
            arsort($sessionNet);
            $pairNetTop = array_slice($pairNet, 0, 12, true);

            // Win rate by pair (resolved only, sorted by lower CI bound = honest ranking)
            $pairWLTop = array();
            foreach(array_keys($topPairs) as $pk){
                if(isset($pairWL[$pk]) && ($pairWL[$pk]['w']+$pairWL[$pk]['l'])>0) $pairWLTop[$pk]=$pairWL[$pk];
            }
            uasort($pairWLTop, function($a,$b){
                $ca = wilson($a['w'],$a['w']+$a['l']);
                $cb = wilson($b['w'],$b['w']+$b['l']);
                if(abs($ca['lo']-$cb['lo']) < 0.0001) return ($b['w']+$b['l'])-($a['w']+$a['l']);
                return ($cb['lo'] < $ca['lo']) ? -1 : 1;
            });

            $sessionWLf = array_filter($sessionWL, function($d){ return ($d['w']+$d['l'])>0; });

            // Lifecycle composition for top pairs, sorted by formation rate ascending (worst first)
            $lifeTop = array();
            foreach(array_keys($topPairs) as $pk){
                $lc = $pairLifecycle[$pk];
                $lifeTop[$pk] = array('Win'=>$lc['win'],'Loss'=>$lc['loss'],'Pending'=>$lc['pending'],'No Setup'=>$lc['notformed']);
            }
            uasort($lifeTop, function($a,$b){
                $ta = array_sum($a); $tb = array_sum($b);
                $fa = $ta>0 ? ($ta-$a['No Setup'])/$ta : 0;
                $fb = $tb>0 ? ($tb-$b['No Setup'])/$tb : 0;
                if(abs($fa-$fb) < 0.0001) return $tb-$ta;
                return ($fa < $fb) ? -1 : 1;
            });

            // Currency attribution (min 4 resolved to appear)
            $ccyTable = array();
            foreach($ccyWL as $c => $d){
                if(($d['w']+$d['l']) >= 4) $ccyTable[$c] = $d;
            }
            uasort($ccyTable, function($a,$b){
                $ra = $a['w']/max(1,$a['w']+$a['l']); $rb = $b['w']/max(1,$b['w']+$b['l']);
                if(abs($ra-$rb)<0.0001) return ($b['w']+$b['l'])-($a['w']+$a['l']);
                return ($rb < $ra) ? -1 : 1;
            });

            // Direction bias: pairs with resolved trades on both sides, or >=3 resolved total
            $biasTable = array();
            foreach($pairBias as $pk => $d){
                $tb = $d['call']['w']+$d['call']['l']; $tsl = $d['put']['w']+$d['put']['l'];
                if($tb+$tsl >= 3) $biasTable[$pk] = $d;
            }
            uasort($biasTable, function($a,$b){
                return (($b['call']['w']+$b['call']['l']+$b['put']['w']+$b['put']['l'])
                      - ($a['call']['w']+$a['call']['l']+$a['put']['w']+$a['put']['l']));
            });
            $biasTable = array_slice($biasTable, 0, 8, true);

            // Weekday x hour heatmap grid
            $heatRows = array();
            foreach($dowNames as $k => $v){ if(isset($heat[$k])) $heatRows[$k]=$v; }
            $heatCols = array();
            foreach(array_keys($hourSeries) as $hk){ $heatCols[$hk] = $hk; }

            // Daily win-rate trend
            $trend = array();
            foreach($dayStats as $d => $s){
                $rv = $s['w']+$s['l'];
                if($rv>0) $trend[date('d M', $s['ts'])] = round(($s['w']/$rv)*100,1);
            }

            // Best / worst callouts
            $bestPair = null; $worstPair = null;
            foreach($pairWLTop as $pk => $d){
                if(($d['w']+$d['l']) >= 3){ if($bestPair === null) $bestPair = $pk; $worstPair = $pk; }
            }
            $leakPair = null; $leakRate = 100;
            foreach($lifeTop as $pk => $segs){
                $t = array_sum($segs);
                if($t >= 5){ $fr = ($t-$segs['No Setup'])/$t*100; if($fr < $leakRate){ $leakRate=$fr; $leakPair=$pk; } }
            }
            $bestSession = null; $bestSessRate = -1;
            foreach($sessionWLf as $sk => $d){
                $t=$d['w']+$d['l'];
                if($t>=5){ $r2=$d['w']/$t*100; if($r2>$bestSessRate){ $bestSessRate=$r2; $bestSession=$sk; } }
            }
            $bestHour = null; $bestHourRate = -1;
            $hourWL = array();
            foreach($heat as $dw => $hh){
                foreach($hh as $hk => $c){
                    if(!isset($hourWL[$hk])) $hourWL[$hk]=array('w'=>0,'l'=>0);
                    $hourWL[$hk]['w'] += $c['w']; $hourWL[$hk]['l'] += $c['l'];
                }
            }
            foreach($hourWL as $hk => $d){
                $t=$d['w']+$d['l'];
                if($t>=4){ $r2=$d['w']/$t*100; if($r2>$bestHourRate){ $bestHourRate=$r2; $bestHour=$hk; } }
            }

            $PALETTE = array(rgb(C_BLUE),rgb(C_GREEN),rgb(C_PURPLE),rgb(C_TEAL),rgb(C_AMBER),
                             array(255,127,80),array(110,120,140),array(86,156,214),
                             array(233,120,170),array(120,190,110));

            /* ================= BUILD PDF ================= */
            $pdf = new PDF();
            $pdf->AliasNbPages();
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->AddPage();

            /* ===== PAGE 1: COVER + KPI + EXEC SUMMARY ===== */
            list($nr,$ng,$nb) = rgb(C_NAVY);
            $pdf->SetFillColor($nr,$ng,$nb);
            $pdf->Rect(0,0,210,42,'F');
            list($br,$bg,$bb) = rgb(C_BLUE);
            $pdf->SetFillColor($br,$bg,$bb);
            $pdf->Rect(0,42,210,2.5,'F');
            $pdf->SetFillColor(255,255,255);
            $pdf->Rect(10,12,3,18,'F');

            $pdf->SetXY(17,11);
            $pdf->SetFont('Arial','B',21);
            $pdf->SetTextColor(255,255,255);
            $pdf->Cell(0,10,'TRADE ANALYTICS REPORT',0,1,'L');
            $pdf->SetX(17);
            $pdf->SetFont('Arial','',9.5);
            $pdf->SetTextColor(178,196,232);
            $pdf->Cell(0,6,"Period  $startDate  to  $endDate",0,1,'L');
            $pdf->SetX(17);
            $pdf->SetFont('Arial','',8);
            $pdf->SetTextColor(140,162,205);
            $pdf->Cell(0,5,"Generated ".date('d M Y, H:i')." IST   |   $totalTrades signals   |   ".count($dayStats)." trading days   |   vs $prevStart to $prevEnd",0,1,'L');

            // KPI ROW 1 (with deltas)
            $y = 52; $bw = 44; $bh = 22; $gx = 4;
            $pdf->StatBox(10,             $y,$bw,$bh,'TOTAL SIGNALS', (string)$totalTrades, C_NAVY,  false, $dTotal, '');
            $pdf->StatBox(10+($bw+$gx),   $y,$bw,$bh,'WIN RATE (RESOLVED)', $winRate.'%', C_GREEN, false, $dWinRate, 'pp');
            $pdf->StatBox(10+2*($bw+$gx), $y,$bw,$bh,'EXECUTION RATE', $executionPct.'%', C_BLUE,  false, $dExec, 'pp');
            $pdf->StatBox(10+3*($bw+$gx), $y,$bw,$bh,'NET (UNITS STAKED)', sprintf('%+.1fu',$netUnits), $netUnits>=0?C_PURPLE:C_RED);

            // KPI ROW 2
            $y2 = $y+$bh+4; $bh2 = 16;
            $pdf->StatBox(10,             $y2,$bw,$bh2,'WINS',             (string)$winCount,       C_GREEN, true);
            $pdf->StatBox(10+($bw+$gx),   $y2,$bw,$bh2,'LOSSES',           (string)$lossCount,      C_RED,   true);
            $pdf->StatBox(10+2*($bw+$gx), $y2,$bw,$bh2,'PENDING',          (string)$pendingCount,   C_AMBER, true);
            $pdf->StatBox(10+3*($bw+$gx), $y2,$bw,$bh2,'SETUP NOT FORMED', (string)$notFormedCount, C_GREY,  true);

            $pdf->SetY($y2+$bh2+7);

            // Edge quality metrics
            $pdf->SectionTitle('Edge Quality','All figures assume a '.round($payout*100).'% payout, flat 1-unit stake, full stake lost on a loss',48);
            $pfTxt = is_infinite($profitFactor) ? 'inf' : round($profitFactor,2);
            $pdf->MetricGrid(10,$pdf->GetY(),190, array(
                'Break-even win rate'      => round($breakeven*100,1).'%',
                'Margin over break-even'   => sprintf('%+.1f pp',$edgeMargin*100),
                'Expectancy per trade'     => sprintf('%+.3f u',$expPerTrade),
                'ROI on staked capital'    => sprintf('%+.1f%%',$roiPct),
                'Net result'               => sprintf('%+.2f u',$netUnits),
                'Profit factor'            => $pfTxt,
                'Win rate 95% CI'          => round($ciAll['lo']*100).'% - '.round($ciAll['hi']*100).'%',
                'Kelly stake'              => round($kellyPct,1).'%',
                'Max win streak'           => $maxWinStreak,
                'Max loss streak'          => $maxLossStreak,
                'Max drawdown'             => sprintf('-%.2f u',$maxDD),
                'Setup formation rate'     => $formationPct.'%',
            ), 4, 15);
            $pdf->SetY($pdf->GetY()+5);

            $pdf->SectionTitle('Payout Sensitivity','Same trade record priced at different broker payouts. Your assumed payout is highlighted',40);
            $pdf->SetY($pdf->SensitivityTable(10,$pdf->GetY(),190,
                array(0.70,0.75,0.80,0.85,0.90,0.95),$winCount,$lossCount,$payout)+5);

            // Executive summary
            $sumLines = array();
            $sumLines[] = "$resolved of $totalTrades signals resolved ($executionPct%); $notFormedCount never formed a setup and $pendingCount are still open.";
            $sumLines[] = "At a ".round($payout*100)."% payout you need ".round($breakeven*100,1)."% to break even. You are at $winRate%, a margin of ".sprintf('%+.1f',$edgeMargin*100)." percentage points.";
            $sumLines[] = "That works out to ".sprintf('%+.3f',$expPerTrade)." units per trade, ".sprintf('%+.2f',$netUnits)." units net, an ROI of ".sprintf('%+.1f%%',$roiPct)." on capital staked.";
            $sumLines[] = ($ciLoProfitable
                ? "The 95% confidence interval (".round($ciAll['lo']*100)."% to ".round($ciAll['hi']*100)."%) sits entirely above break-even on n=$resolved, so the edge is unlikely to be pure luck."
                : "The 95% confidence interval (".round($ciAll['lo']*100)."% to ".round($ciAll['hi']*100)."%) still straddles break-even on n=$resolved, so the edge is not yet statistically established.");
            $sumLines[] = "Worst drawdown ".sprintf('%.2f',$maxDD)." units, longest losing run $maxLossStreak. Full-Kelly stake would be ".round($kellyPct,1)."% of bankroll; quarter-Kelly (".round($kellyPct/4,1)."%) is the safer real-world setting.";
            if($bestPair !== null && $bestPair !== $worstPair){
                $bd = $pairWLTop[$bestPair]; $wd = $pairWLTop[$worstPair];
                $sumLines[] = "Best pair (min. 3 resolved): $bestPair at ".round($bd['w']/($bd['w']+$bd['l'])*100)."% on n=".($bd['w']+$bd['l']).
                              ".  Weakest: $worstPair at ".round($wd['w']/($wd['w']+$wd['l'])*100)."% on n=".($wd['w']+$wd['l']).".";
            }
            if($bestSession !== null) $sumLines[] = "Strongest session: $bestSession at ".round($bestSessRate)."%.".($bestHour!==null ? "  Strongest hour: {$bestHour}:00 IST at ".round($bestHourRate)."%." : "");
            if($leakPair !== null)    $sumLines[] = "Biggest leak: $leakPair only forms a setup ".round($leakRate)."% of the time - most of its alerts are noise.";
            if($stalePending > 0)     $sumLines[] = "$stalePending pending signals are more than 24 h old and may need manual resolution before they distort future reports.";
            $pdf->Callout('EXECUTIVE SUMMARY', $sumLines, C_BLUE);

            /* ===== PAGE 2: DISTRIBUTION + EQUITY CURVE ===== */
            $pdf->showHeaderBar = true;
            $pdf->sectionName = 'Overview';
            $pdf->AddPage();
            $pdf->SetY(22);

            $pdf->SectionTitle('Overview Distribution','Signal direction split and lifecycle outcome of every signal',66);
            $cy = $pdf->GetY() + 24;

            $dirData   = array('CALL'=>$upCount, 'PUT'=>$downCount);
            $dirColors = array(rgb(C_BLUE), rgb(C_AMBER));
            $pdf->PieChart(36,$cy,21,$dirData,$dirColors,false);
            $pdf->Legend(64,$cy-6,$dirData,$dirColors,$totalTrades);
            $pdf->SetXY(12,$cy+26);
            $pdf->SetFont('Arial','B',8.5);
            $pdf->SetTextColor(45,52,66);
            $pdf->Cell(48,5,'Direction Split',0,0,'C');

            $outData = array(); $outColors = array();
            if($winCount>0)      { $outData['Win']=$winCount;             $outColors[]=rgb(C_GREEN); }
            if($lossCount>0)     { $outData['Loss']=$lossCount;           $outColors[]=rgb(C_RED); }
            if($pendingCount>0)  { $outData['Pending']=$pendingCount;     $outColors[]=rgb(C_AMBER); }
            if($notFormedCount>0){ $outData['No Setup']=$notFormedCount;  $outColors[]=rgb(C_GREY); }
            if(!$outData){ $outData['No Data']=1; $outColors[]=array(210,210,210); }

            $pdf->PieChart(134,$cy,21,$outData,$outColors,true,(string)$totalTrades);
            $pdf->Legend(162,$cy-12,$outData,$outColors,$totalTrades);
            $pdf->SetXY(110,$cy+26);
            $pdf->SetFont('Arial','B',8.5);
            $pdf->SetTextColor(45,52,66);
            $pdf->Cell(48,5,'Outcome Split',0,0,'C');

            $pdf->SetY($cy+36);

            $pdf->SectionTitle('Equity Curve','Cumulative units at a '.round($payout*100).'% payout: +'.$payout.'u per win, -1.00u per loss, flat stake',60);
            $endY = $pdf->EquityCurve(20,$pdf->GetY()+2,168,44,$equity,$bestPeakIdx,$troughIdx);
            $pdf->SetY($endY+2);
            $pdf->InlineLegend(20,$pdf->GetY(), array('Drawdown peak'=>C_GREEN,'Drawdown trough'=>C_RED));
            $pdf->SetY($pdf->GetY()+8);

            $ddLines = array();
            $ddLines[] = "Ends at ".sprintf('%+.2f',$netUnits)." units across $resolved resolved trades. Deepest peak-to-trough decline: ".sprintf('%.2f',$maxDD)." units.";
            $ddLines[] = "Longest winning run $maxWinStreak, longest losing run $maxLossStreak. Because a binary loss costs a full unit but a win returns only ".sprintf('%.2f',$payout).", losing runs bite harder than winning runs help.";
            $ddLines[] = "A bankroll of at least ".ceil($maxDD*3)." units would have absorbed the worst observed drawdown with a 3x safety buffer.";
            if($stakeAmt > 0){
                $ddLines[] = "At your stake of ".number_format($stakeAmt,2)." per trade that is a net of ".number_format($netUnits*$stakeAmt,2).", a worst drawdown of ".number_format($maxDD*$stakeAmt,2).", and a suggested bankroll of ".number_format(ceil($maxDD*3)*$stakeAmt,2).".";
            }
            $pdf->Callout('READING THE CURVE', $ddLines, C_PURPLE, 248,245,254);

            /* ===== PAGE 3: VOLUME & TIMING ===== */
            $pdf->sectionName = 'Volume & Timing';
            $pdf->AddPage();
            $pdf->SetY(22);

            $pdf->SectionTitle('Top Traded Pairs','Signal volume, top 10 by count', count($topPairs)*9.7+4);
            $endY = $pdf->HBarChart(12,$pdf->GetY(),186,$topPairs,$PALETTE,6.5,3.2,30);
            $pdf->SetY($endY+6);

            $pdf->SectionTitle('Session Distribution','Which market sessions your signals fire in', count($sessionCount)*10.5+4);
            $endY = $pdf->HBarChart(12,$pdf->GetY(),186,$sessionCount,
                array(rgb(C_PURPLE),rgb(C_TEAL),rgb(C_AMBER),rgb(C_BLUE),rgb(C_GREY)),7,3.5,30);
            $pdf->SetY($endY+6);

            $pdf->SectionTitle('Hourly Activity (IST)','Signal count per hour across the active trading window',48);
            $endY = $pdf->ColumnChart(20,$pdf->GetY(),176,38,$hourSeries,C_BLUE);
            $pdf->SetY($endY+4);

            /* ===== PAGE 4: PERFORMANCE ===== */
            $pdf->sectionName = 'Performance';
            $pdf->AddPage();
            $pdf->SetY(22);

            $pdf->SectionTitle('Win Rate by Currency Pair','Ranked by lower confidence bound. Black line marks the '.round($breakeven*100,1).'% break-even level; faded bars have under 5 resolved trades', count($pairWLTop)*11+12);
            $endY = $pdf->StackedBar(12,$pdf->GetY(),186,$pairWLTop,7,4,30,5,true,$breakeven);
            $pdf->SetY($endY+1);
            $pdf->InlineLegend(12,$pdf->GetY(), array('Win'=>C_GREEN,'Loss'=>C_RED));
            $pdf->SetY($pdf->GetY()+4);

            $pdf->SectionTitle('Win Rate by Session','', count($sessionWLf)*12+6);
            $endY = $pdf->StackedBar(12,$pdf->GetY(),186,$sessionWLf,7.5,4.5,30,5,true,$breakeven);
            $pdf->SetY($endY+5);

            $pdf->SectionTitle('Win Rate by Direction','',34);
            $endY = $pdf->StackedBar(12,$pdf->GetY(),186,$dirWL,9,5,30,5,true,$breakeven);
            $pdf->SetY($endY+5);

            if(count($pairNetTop)){
                $pdf->SectionTitle('Net Units by Pair','Actual profit and loss at a '.round($payout*100).'% payout. A pair can win often and still lose money',
                    count($pairNetTop)*9.9+8);
                $endY = $pdf->SignedBar(12,$pdf->GetY()+2,186,$pairNetTop,6.5,3.4,28);
                $pdf->SetY($endY+5);
            }

            if(count($trend) > 1){
                $pdf->SectionTitle('Daily Win-Rate Trend','Resolved trades per day. Red line marks the '.round($breakeven*100,1).'% break-even level',48);
                $endY = $pdf->LineChart(22,$pdf->GetY()+3,172,34,$trend,C_GREEN,$breakeven*100);
                $pdf->SetY($endY+4);
            }

            /* ===== PAGE 5: SETUP QUALITY ===== */
            $pdf->sectionName = 'Setup Quality';
            $pdf->AddPage();
            $pdf->SetY(22);

            $pdf->SectionTitle('Setup Formation Rate by Pair','Worst first. Pairs that rarely form a setup are generating alerts you cannot act on', count($lifeTop)*10.6+12);
            $endY = $pdf->LifecycleBar(12,$pdf->GetY(),186,$lifeTop,
                array(C_GREEN,C_RED,C_AMBER,C_GREY),7,3.6,28);
            $pdf->SetY($endY+1);
            $pdf->InlineLegend(12,$pdf->GetY(), array('Win'=>C_GREEN,'Loss'=>C_RED,'Pending'=>C_AMBER,'No Setup'=>C_GREY));
            $pdf->SetY($pdf->GetY()+5);

            if(count($ccyTable)){
                $pdf->SectionTitle('Currency Attribution','Win rate for every trade where the currency appears on either leg (min. 4 resolved)', count($ccyTable)*10.6+8);
                $endY = $pdf->StackedBar(12,$pdf->GetY(),186,$ccyTable,7,3.6,22,5,true,$breakeven);
                $pdf->SetY($endY+5);
            }

            $pdf->SectionTitle('Time to Resolution','Win rate bucketed by how long a signal took to resolve', count($durBuckets)*11.5+8);
            $durFiltered = array_filter($durBuckets, function($d){ return ($d['w']+$d['l'])>0; });
            if(count($durFiltered)){
                $endY = $pdf->StackedBar(12,$pdf->GetY(),186,$durFiltered,7.5,4,30,5,true,$breakeven);
                $pdf->SetY($endY+3);
            } else {
                $pdf->SetFont('Arial','',8);
                $pdf->SetTextColor(150,156,168);
                $pdf->Cell(0,6,'No usable resolution timestamps in this period.',0,1,'L');
            }

            if($pendingCount > 0){
                $ages = $pendingAges; sort($ages);
                $oldest = count($ages) ? end($ages) : 0;
                $pdf->Callout('PENDING BACKLOG', array(
                    "$pendingCount signals are still unresolved. $stalePending of them are over 24 h old; the oldest is ".round($oldest)." h.",
                    "Unresolved signals are excluded from win-rate maths, so a large backlog makes the headline number less meaningful.",
                ), C_AMBER, 254,249,238);
            }

            /* ===== PAGE 6: TIMING QUALITY ===== */
            if(count($heatRows) && count($heatCols)){
                $pdf->sectionName = 'Timing Quality';
                $pdf->AddPage();
                $pdf->SetY(22);

                $pdf->SectionTitle('Weekday x Hour Heatmap','Cell colour is win rate, number is signal count. Pale cells have too few resolved trades to read', count($heatRows)*9+24);
                $cellW = min(13, 176/max(1,count($heatCols)));
                $endY = $pdf->Heatmap(16,$pdf->GetY()+5,$cellW,9,$heatRows,$heatCols,$heat,18);
                $pdf->SetY($endY+6);
                $pdf->SetY($pdf->HeatScale(30,$pdf->GetY(),60));
                $pdf->SetY($pdf->GetY()+4);

                $dowWLn = array();
                foreach($dowWL as $k => $d){ if(($d['w']+$d['l'])>0) $dowWLn[$dowNames[$k]] = $d; }
                if(count($dowWLn)){
                    $pdf->SectionTitle('Win Rate by Weekday','', count($dowWLn)*11.5+6);
                    $endY = $pdf->StackedBar(12,$pdf->GetY(),186,$dowWLn,7.5,4,30,5,true,$breakeven);
                    $pdf->SetY($endY+5);
                }

                if(count($biasTable)){
                    $pdf->SectionTitle('Direction Bias by Pair','Same pair, long vs short. A large gap suggests a one-sided edge', count($biasTable)*11+8);
                    $endY = $pdf->BiasChart(12,$pdf->GetY()+2,186,$biasTable,28,11);
                    $pdf->SetY($endY+4);
                }
            }


            /* ===== COMPOUNDING: THE LADDER MATHS ===== */
            $pdf->sectionName = 'Compounding';
            $pdf->AddPage();
            $pdf->SetY(22);

            $ladder = martingaleLadder($payout,$maxDepth);
            $evPerUnit = $p*$payout - (1-$p);

            $pdf->Callout('READ THIS BEFORE THE TABLES', array(
                "Expectancy per unit staked is ".sprintf('%+.4f',$evPerUnit)." for flat, 2x martingale and parlay alike. Compounding changes how much capital is exposed and how losses arrive - never the underlying edge.",
                "Every figure on these pages assumes outcomes are independent. The runs test on this page checks that assumption; if it fails, the ruin probabilities shown are optimistic.",
                "Per-pair recommendations require far more resolved trades than you currently have. Read the verdict column before acting on any row.",
            ), C_RED, 254,242,242);

            // --- capital ladder ---
            $pdf->SectionTitle('2x Martingale Capital & Recovery','Double the last stake after each loss. At a '.round($payout*100).'% payout a win does NOT fully recover prior losses - watch the Net column turn negative',
                $maxDepth*7+16);
            $ladRows = array();
            for($k=1;$k<=$maxDepth;$k++){
                $netK = $ladder['net'][$k];
                $ladRows[] = array(
                    array('Step '.$k,null,true),
                    array(sprintf('%.0f u',$ladder['stakes'][$k])),
                    array(sprintf('%.0f u',$ladder['cum'][$k]), $ladder['cum'][$k]>20?C_RED:($ladder['cum'][$k]>8?C_AMBER:C_GREEN), true),
                    array(sprintf('%+.2f u',$netK), $netK>0?C_GREEN:C_RED, true),
                    array($stakeAmt>0 ? number_format($ladder['cum'][$k]*$stakeAmt,2) : '-'),
                );
            }
            $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),array(
                array('Depth',26,'L'),array('Stake this step',36),
                array('Cumulative at risk',44),array('NET profit if it wins',44),
                array('In your money',30),
            ),$ladRows,7,8)+4);

            $bs = $ladder['breakStep'];
            $pdf->Callout('THE 2x TRAP AT '.round($payout*100).'% PAYOUT', array(
                ($bs !== null
                    ? "Winning at step $bs or later LOSES money even though you won. Net profit goes negative there because the ".round($payout*100)."% payout no longer covers the doubled cumulative stake."
                    : "At this payout the ladder still recovers through step $maxDepth."),
                "A true break-even recovery ladder would need to multiply stakes by ".round((1+$payout)/$payout,3)."x per step, not 2x. Doubling only fully recovers when the payout is 100% or more.",
                "Practical read: a 2x ladder past step ".($bs!==null?($bs-1):$maxDepth)." digs the hole deeper on a win. Cap the ladder there or switch to the recovery ratio.",
            ), C_RED, 254,242,242);

            // --- ruin by depth ---
            $pdf->SectionTitle('Ruin Probability by Ladder Depth','Chance the ladder busts at least once over '.$horizon.' trades. Computed from the Wilson lower bound ('.round($ciAll['lo']*100,1).'%), not the point estimate',
                $maxDepth*7+16);
            $pLo = max(0.01,min(0.99,$ciAll['lo']));
            $safeDepth = null;
            $depthRows = array();
            for($N=1;$N<=$maxDepth;$N++){
                $ruinPt = martingaleRuin($p,$N,$horizon);
                $ruinLo = martingaleRuin($pLo,$N,$horizon);
                $ok = ($ruinLo <= $ruinMax);
                if($ok && $safeDepth === null) $safeDepth = $N;
                $depthRows[] = array(
                    array('N = '.$N,null,true),
                    array(sprintf('%.2f%%',pow(1-$p,$N)*100)),
                    array(sprintf('%.1f%%',$ruinPt*100), $ruinPt<=$ruinMax?C_GREEN:C_RED),
                    array(sprintf('%.1f%%',$ruinLo*100), $ok?C_GREEN:C_RED, true),
                    array(sprintf('%.2f u',$ladder['cum'][$N])),
                    array($ok ? 'WITHIN LIMIT' : 'EXCEEDS LIMIT', $ok?C_GREEN:C_RED, true),
                );
            }
            $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),array(
                array('Depth',24,'L'),array('P(N losses)',28),
                array('Ruin at '.round($p*100,1).'%',32),array('Ruin at CI low',32),
                array('Capital needed',32),array('Vs your '.round($ruinMax*100).'% limit',42),
            ),$depthRows,7,8)+5);

            $depthVerdict = ($safeDepth === null)
                ? "No ladder depth up to $maxDepth keeps ruin under ".round($ruinMax*100)."% on the conservative win-rate estimate."
                : "Shallowest depth meeting your ".round($ruinMax*100)."% ruin limit is N = $safeDepth, which demands ".sprintf('%.1f',$ladder['cum'][$safeDepth])." units of bankroll - ".round($ladder['cum'][$safeDepth])."x your base stake.";
            $pdf->Callout('DEPTH VERDICT', array(
                $depthVerdict,
                "Deeper 2x ladders cut per-cycle ruin but the exposure doubles each step: step $maxDepth alone stakes ".sprintf('%.0f',$ladder['stakes'][$maxDepth])." units on a cumulative ".sprintf('%.0f',$ladder['cum'][$maxDepth])."-unit hole.",
                "Ruin here means the ladder busts once. A single bust at depth N wipes ".sprintf('%.1f',$ladder['cum'][$maxDepth])." units, which takes ".round($ladder['cum'][$maxDepth]/max(0.001,$evPerUnit))." break-even trades to earn back.",
            ), C_AMBER, 254,249,238);

            /* ===== STREAK EVIDENCE & INDEPENDENCE ===== */
            $pdf->AddPage();
            $pdf->SetY(22);

            $sd = streakDist($seqAll,$p,5);
            $pdf->SectionTitle('Loss-Streak Distribution','Observed runs against the Bernoulli expectation at your win rate. Bars to the right of expectation mean losses cluster more than chance',54);
            $endY = $pdf->StreakChart(20,$pdf->GetY()+2,168,40,$sd['obs'],$sd['exp'],5);
            $pdf->SetY($endY+2);
            $pdf->SetY($pdf->InlineLegend(20,$pdf->GetY(),array('Expected by chance'=>C_GREY,'Actually observed'=>C_BLUE))+4);

            $rt = runsTest($seqAll);
            $pdf->SectionTitle('Independence Test (Wald-Wolfowitz Runs)','Compounding maths assumes each trade is independent of the last. This tests whether that holds',34);
            if($rt !== null){
                $rtCol = ($rt['verdict']==='CLUSTERED') ? C_RED : (($rt['verdict']==='ALTERNATING') ? C_AMBER : C_GREEN);
                $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),array(
                    array('Runs observed',44,'L'),array('Runs expected',44),array('Z score',44),array('Verdict',58),
                ),array(array(
                    array((string)$rt['R'],null,true),
                    array(sprintf('%.1f',$rt['exp'])),
                    array(sprintf('%+.2f',$rt['z']), $rtCol, true),
                    array($rt['verdict'], $rtCol, true),
                )),7,8)+4);

                $rtLines = array();
                if($rt['verdict']==='CLUSTERED'){
                    $rtLines[] = "Losses arrive in clusters more than chance would produce. Every ruin probability in this report is therefore UNDERSTATED - real martingale risk is higher than the tables show.";
                    $rtLines[] = "Likely causes: correlated pairs firing together, one session dominating, or a regime your signal handles badly. Check the currency attribution and heatmap pages.";
                } elseif($rt['verdict']==='ALTERNATING'){
                    $rtLines[] = "Wins and losses alternate more than chance. Unusual - worth checking whether results are being recorded correctly.";
                } else {
                    $rtLines[] = "Outcomes are statistically indistinguishable from independent at n=".count($seqAll).". The ruin formulas in this report are on reasonable footing, within the limits of the sample size.";
                }
                $rtLines[] = "Caveat: this test has weak power below roughly 50 resolved trades. You have ".count($seqAll).".";
                $pdf->Callout('WHAT THE TEST MEANS', $rtLines, $rtCol==C_GREEN?C_GREEN:C_RED, $rtCol==C_GREEN?242:254, $rtCol==C_GREEN?250:242, $rtCol==C_GREEN?245:242);
            } else {
                $pdf->SetFont('Arial','',8);
                $pdf->SetTextColor(150,156,168);
                $pdf->Cell(0,6,'Not enough resolved trades to run the test (needs at least 8).',0,1,'L');
                $pdf->Ln(3);
            }

            // --- parlay vs martingale vs flat, analytic ---
            $pdf->SectionTitle('Parlay Depth Economics','Whole balance rides each step - it grows by '.round(1+$payout,2).'x per win ('.round(1+$payout,2).', '.round(pow(1+$payout,2),2).', '.round(pow(1+$payout,3),3).', ...). One loss returns the cycle to zero',$maxDepth*7+16);
            $parRows = array();
            for($N=1;$N<=$maxDepth;$N++){
                $ps = parlayStats($p,$payout,$N);
                $psLo = parlayStats($pLo,$payout,$N);
                $parRows[] = array(
                    array('N = '.$N,null,true),
                    array(sprintf('%.1f%%',$ps['pComplete']*100), $ps['pComplete']>=0.5?C_GREEN:($ps['pComplete']>=0.25?C_AMBER:C_RED)),
                    array(sprintf('%.3f u',$ps['balance'])),
                    array(sprintf('%+.3f u',$ps['evCycle']), $ps['evCycle']>=0?C_GREEN:C_RED),
                    array(sprintf('%+.3f u',$psLo['evCycle']), $psLo['evCycle']>=0?C_GREEN:C_RED, true),
                    array('1.00 u', C_GREEN),
                );
            }
            $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),array(
                array('Depth',24,'L'),array('P(all N win)',30),array('Balance if complete',36),
                array('EV/cycle at '.round($p*100,1).'%',36),array('EV/cycle at CI low',36),array('Max loss/cycle',28),
            ),$parRows,7,8)+4);

            $xVal = $p*(1+$payout);
            $xLo  = $pLo*(1+$payout);
            $pdf->Callout('PARLAY VERDICT', array(
                "The governing quantity is p x (1+payout) = ".sprintf('%.4f',$xVal).". Above 1.0, riding deeper raises expected value per cycle; below 1.0 it destroys value.",
                ($xLo>1
                    ? "Even on the conservative Wilson lower bound it is ".sprintf('%.4f',$xLo).", still above 1.0 - the parlay edge survives the pessimistic reading."
                    : "On the conservative Wilson lower bound it falls to ".sprintf('%.4f',$xLo).", BELOW 1.0. Your edge is not established well enough to ride deep."),
                "But P(all N win) falls fast: ".round(pow($p,2)*100)."% for N=2, ".round(pow($p,3)*100)."% for N=3, ".round(pow($p,5)*100)."% for N=5. Most deep cycles fail, so the big balances are rare - the EV is real but the variance is punishing.",
                "Structural advantage over the 2x martingale: max loss per cycle is 1.00 unit at ANY depth, versus ".sprintf('%.0f',$ladder['cum'][$maxDepth])." units of exposure for a ".$maxDepth."-step double. That capped downside is why parlay is the safer way to compound a binary edge.",
                "Practical setting: ride 2 to 3 steps, then bank it. Chasing N=5+ turns a steady edge into a lottery.",
            ), $xLo>1 ? C_GREEN : C_RED, $xLo>1?242:254, $xLo>1?250:242, $xLo>1?245:242);

            /* ===== PER-PAIR COMPOUNDING SUITABILITY ===== */
            $pdf->AddPage();
            $pdf->SetY(22);
            $pdf->SectionTitle('Compounding Suitability by Pair','Every pair scored on sample adequacy first. Recommendations are withheld where the data cannot support them',
                min(14,count($pairWL))*7+20);

            $need = tradesNeeded($p,0.07);
            $pairRows = array();
            $anyMeasurable = false;
            $pairsByN = $pairWL;
            uasort($pairsByN, function($a,$b){ return ($b['w']+$b['l']) - ($a['w']+$a['l']); });
            $shown = 0;
            foreach($pairsByN as $pk => $d){
                $nres = $d['w']+$d['l'];
                if($nres == 0) continue;
                if($shown++ >= 14) break;
                $ci = wilson($d['w'],$nres);
                list($verd,$vcol) = sampleVerdict($nres);
                if($verd === 'MEASURABLE') $anyMeasurable = true;
                $pLoP = max(0.01,min(0.99,$ci['lo']));

                // shallowest martingale depth meeting the ruin limit on the conservative estimate
                $sd2 = null;
                for($N=1;$N<=$maxDepth;$N++){
                    if(martingaleRuin($pLoP,$N,$horizon) <= $ruinMax){ $sd2 = $N; break; }
                }
                $parLo = $pLoP*(1+$payout);

                // parlay depth is the recommendation when p(1+b) holds up on the low bound
                $parDepth = null;
                if($parLo > 1.0){
                    for($N=2;$N<=$maxDepth;$N++){ if(pow($pLoP,$N) >= 0.25) $parDepth = $N; else break; }
                    if($parDepth === null) $parDepth = 1;
                }
                $rec = ($verd === 'MEASURABLE')
                    ? ($parLo>1 ? 'Parlay '.($parDepth>1?('N='.$parDepth):'N=1 (flat)') : 'Flat stake only')
                    : 'NO RECOMMENDATION';
                $recCol = ($verd === 'MEASURABLE') ? ($parLo>1 ? C_GREEN : C_AMBER) : C_GREY;

                $pairRows[] = array(
                    array($pk,null,true),
                    array((string)$nres, $vcol, true),
                    array(round($ci['p']*100).'%'),
                    array(round($ci['lo']*100).'-'.round($ci['hi']*100).'%'),
                    array($verd, $vcol, true),
                    array($parLo>1 ? sprintf('%.3f',$parLo) : sprintf('%.3f',$parLo), $parLo>1?C_GREEN:C_RED),
                    array($sd2 !== null ? 'N='.$sd2.' ('.sprintf('%.0f',$ladder['cum'][$sd2]).'u)' : 'none <= limit', $sd2!==null?null:C_RED),
                    array($rec, $recCol, true),
                    array((string)max(0,$need-$nres), $need-$nres>0?C_RED:C_GREEN),
                );
            }
            $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),array(
                array('Pair',20,'L'),array('n',10),array('WR',14),array('95% CI',24),
                array('Sample',26),array('p(1+b)',18),array('Martingale',30),array('Recommendation',30),array('More needed',18),
            ),$pairRows,7,8)+5);

            $gateLines = array();
            $gateLines[] = "Sample gate: under 30 resolved = INSUFFICIENT, 30 to 79 = PROVISIONAL, 80 or more = MEASURABLE. A compounding plan is only issued for MEASURABLE pairs.";
            $gateLines[] = "To pin a pair's win rate to within 7 percentage points at 95% confidence you need roughly $need resolved trades on that pair alone. Your largest sample is ".($shown>0 ? max(array_map(function($d){return $d['w']+$d['l'];}, $pairWL)) : 0).".";
            if(!$anyMeasurable){
                $gateLines[] = "NO PAIR in this period reaches the measurable threshold. Every per-pair compounding number above is illustrative only - trading it would be acting on noise.";
                $gateLines[] = "The p(1+b) column is still informative in aggregate: it shows which pairs would need to hold up, not which ones have.";
            }
            $pdf->Callout('WHY MOST ROWS SAY NO RECOMMENDATION', $gateLines, $anyMeasurable?C_AMBER:C_RED, $anyMeasurable?254:254, $anyMeasurable?249:242, $anyMeasurable?238:242);

            /* ===== MONTE CARLO ===== */
            $pdf->AddPage();
            $pdf->SetY(22);
            $pdf->SectionTitle('Monte Carlo Simulation','Each row is '.number_format($mcRuns).' simulated runs of '.$horizon.' trades at a '.round($p*100,1).'% win rate, starting from a 100-unit bankroll with a 1-unit base stake',
                (1+2*$maxDepth)*7+20);

            $mcRows = array();
            $mcFlat = mcSim($p,$payout,'flat',1,$mcRuns,$horizon,100);
            $mcRows[] = array(
                array('Flat stake',null,true),
                array('-'),
                array(sprintf('%.1f',$mcFlat['p50']), $mcFlat['p50']>=100?C_GREEN:C_RED, true),
                array(sprintf('%.1f',$mcFlat['p05']), $mcFlat['p05']>=100?C_GREEN:C_RED),
                array(sprintf('%.1f',$mcFlat['p95'])),
                array(sprintf('%.1f%%',$mcFlat['ruin']*100), $mcFlat['ruin']<=$ruinMax?C_GREEN:C_RED, true),
                array('1.0 u'),
            );
            for($N=2;$N<=$maxDepth;$N++){
                $m = mcSim($p,$payout,'martingale',$N,$mcRuns,$horizon,100);
                $mcRows[] = array(
                    array('Martingale',null,true),
                    array('N='.$N),
                    array(sprintf('%.1f',$m['p50']), $m['p50']>=100?C_GREEN:C_RED, true),
                    array(sprintf('%.1f',$m['p05']), $m['p05']>=100?C_GREEN:C_RED),
                    array(sprintf('%.1f',$m['p95'])),
                    array(sprintf('%.1f%%',$m['ruin']*100), $m['ruin']<=$ruinMax?C_GREEN:C_RED, true),
                    array(sprintf('%.1f u',$ladder['cum'][$N])),
                );
            }
            for($N=2;$N<=$maxDepth;$N++){
                $m = mcSim($p,$payout,'parlay',$N,$mcRuns,$horizon,100);
                $mcRows[] = array(
                    array('Parlay',null,true),
                    array('N='.$N),
                    array(sprintf('%.1f',$m['p50']), $m['p50']>=100?C_GREEN:C_RED, true),
                    array(sprintf('%.1f',$m['p05']), $m['p05']>=100?C_GREEN:C_RED),
                    array(sprintf('%.1f',$m['p95'])),
                    array(sprintf('%.1f%%',$m['ruin']*100), $m['ruin']<=$ruinMax?C_GREEN:C_RED, true),
                    array('1.0 u'),
                );
            }
            $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),array(
                array('Style',30,'L'),array('Depth',20),array('Median end',30),
                array('5th pct',28),array('95th pct',28),array('Ruin rate',28),array('Max exposure',26),
            ),$mcRows,7,8)+5);

            $pdf->Callout('HOW TO READ THIS TABLE', array(
                "Median end tells you the typical outcome. The 5th percentile tells you the bad-but-not-rare outcome, and that is the number that actually determines whether you can keep trading.",
                "A style with a higher median and a much lower 5th percentile is not better - it is the same edge with more variance bolted on.",
                "The simulation assumes independence and a FIXED win rate. Both are optimistic: your real win rate is uncertain (95% CI ".round($ciAll['lo']*100)."% to ".round($ciAll['hi']*100)."%) and drifts over time.",
                "Simulated at your point estimate. At the CI lower bound of ".round($ciAll['lo']*100,1)."%, every ruin figure rises substantially.",
            ), C_BLUE);

            /* ===== DAILY TABLE ===== */
            $pdf->sectionName = 'Daily Summary';
            $pdf->AddPage();
            $pdf->SetY(22);
            $pdf->SectionTitle('Daily Performance Summary','',24);

            $dHead = array('Date','Total','Call','Put','Win','Loss','Pend','No Setup','Win %','Net (u)');
            $dW    = array(28,15,13,13,13,13,14,18,34,29);
            $pdf->SetFont('Arial','B',8.5);
            list($nr,$ng,$nb) = rgb(C_NAVY);
            $pdf->SetFillColor($nr,$ng,$nb);
            $pdf->SetTextColor(255,255,255);
            $pdf->SetDrawColor($nr,$ng,$nb);
            foreach($dHead as $i=>$hh) $pdf->Cell($dW[$i],8,$hh,1,0,'C',true);
            $pdf->Ln();

            $fill=false;
            $pdf->SetFont('Arial','',8.5);
            $pdf->SetDrawColor(222,227,236);
            foreach($dayStats as $d => $s){
                $pdf->NeedSpace(9);
                $rv = $s['w']+$s['l'];
                $wp = $rv>0 ? round(($s['w']/$rv)*100,1) : null;
                $bgv = $fill?248:255;
                $pdf->SetFillColor($bgv,$bgv==255?255:250,$bgv==255?255:253);
                $pdf->SetTextColor(45,52,66);
                $pdf->Cell($dW[0],7,$d,1,0,'C',true);
                $pdf->SetFont('Arial','B',8.5);
                $pdf->Cell($dW[1],7,$s['t'],1,0,'C',true);
                $pdf->SetFont('Arial','',8.5);
                $pdf->Cell($dW[2],7,$s['u'],1,0,'C',true);
                $pdf->Cell($dW[3],7,$s['d'],1,0,'C',true);
                list($gr,$gg,$gb) = rgb(C_GREEN);
                $pdf->SetTextColor($gr,$gg,$gb);
                $pdf->SetFont('Arial','B',8.5);
                $pdf->Cell($dW[4],7,$s['w'],1,0,'C',true);
                list($rr,$rg,$rb) = rgb(C_RED);
                $pdf->SetTextColor($rr,$rg,$rb);
                $pdf->Cell($dW[5],7,$s['l'],1,0,'C',true);
                $pdf->SetFont('Arial','',8.5);
                $pdf->SetTextColor(150,120,20);
                $pdf->Cell($dW[6],7,$s['p'],1,0,'C',true);
                $pdf->SetTextColor(130,138,150);
                $pdf->Cell($dW[7],7,$s['n'],1,0,'C',true);

                $cx = $pdf->GetX(); $cyy = $pdf->GetY();
                $pdf->Cell($dW[8],7,'',1,0,'C',true);
                if($wp !== null){
                    // colour against break-even, not 50
                    $col = ($wp/100 >= $breakeven+0.05) ? C_GREEN : (($wp/100 < $breakeven) ? C_RED : C_AMBER);
                    $pdf->MiniBar($cx+2,$cyy+2.2,15,2.6,$wp,$col);
                    // break-even tick on the mini bar
                    $pdf->SetDrawColor(30,30,30);
                    $pdf->SetLineWidth(0.3);
                    $pdf->Line($cx+2+15*$breakeven,$cyy+1.8,$cx+2+15*$breakeven,$cyy+5.4);
                    $pdf->SetLineWidth(0.2);
                    $pdf->SetDrawColor(222,227,236);
                    list($cr2,$cg2,$cb2) = rgb($col);
                    $pdf->SetXY($cx+18,$cyy);
                    $pdf->SetFont('Arial','B',8);
                    $pdf->SetTextColor($cr2,$cg2,$cb2);
                    $pdf->Cell(14,7,$wp.'%',0,0,'R');
                } else {
                    $pdf->SetXY($cx,$cyy);
                    $pdf->SetFont('Arial','',7.5);
                    $pdf->SetTextColor(165,172,184);
                    $pdf->Cell($dW[8],7,'unresolved',0,0,'C');
                }
                // net units for the day
                $pdf->SetXY($cx+$dW[8],$cyy);
                $dayNet = $s['w']*$payout - $s['l'];
                if($rv>0){
                    if($dayNet>=0){ list($cr3,$cg3,$cb3)=rgb(C_GREEN); } else { list($cr3,$cg3,$cb3)=rgb(C_RED); }
                    $pdf->SetTextColor($cr3,$cg3,$cb3);
                    $pdf->SetFont('Arial','B',8.5);
                    $pdf->Cell($dW[9],7,sprintf('%+.2f',$dayNet),1,0,'C',true);
                } else {
                    $pdf->SetTextColor(175,181,192);
                    $pdf->SetFont('Arial','',8.5);
                    $pdf->Cell($dW[9],7,'-',1,0,'C',true);
                }
                $pdf->SetXY(10,$cyy+7);
                $pdf->SetFont('Arial','',8.5);
                $fill=!$fill;
            }

            $pdf->SetFont('Arial','B',8.5);
            $pdf->SetFillColor(236,240,247);
            $pdf->SetTextColor(25,42,86);
            $pdf->SetDrawColor($nr,$ng,$nb);
            $pdf->Cell($dW[0],8,'TOTAL',1,0,'C',true);
            $pdf->Cell($dW[1],8,$totalTrades,1,0,'C',true);
            $pdf->Cell($dW[2],8,$upCount,1,0,'C',true);
            $pdf->Cell($dW[3],8,$downCount,1,0,'C',true);
            $pdf->Cell($dW[4],8,$winCount,1,0,'C',true);
            $pdf->Cell($dW[5],8,$lossCount,1,0,'C',true);
            $pdf->Cell($dW[6],8,$pendingCount,1,0,'C',true);
            $pdf->Cell($dW[7],8,$notFormedCount,1,0,'C',true);
            $pdf->Cell($dW[8],8,$winRate.'%',1,0,'C',true);
            if($netUnits>=0){ list($cr3,$cg3,$cb3)=rgb(C_GREEN); } else { list($cr3,$cg3,$cb3)=rgb(C_RED); }
            $pdf->SetTextColor($cr3,$cg3,$cb3);
            $pdf->Cell($dW[9],8,sprintf('%+.2f',$netUnits),1,1,'C',true);







/* ===== PAIR CORRELATION & SIMULTANEOUS SIGNAL ANALYSIS ===== */
$pdf->sectionName = 'Correlation & Clustering';
$pdf->AddPage();
$pdf->SetY(22);

// ── Build per-resolved-trade data keyed by timestamp ──────────────────
// For correlation we need the win/loss sequence per pair (chronological).
// seqPair is already built above: $seqPair[$pair] = [1,0,1,...]

// ── 1. PAIR CORRELATION MATRIX ───────────────────────────────────────
// Phi (Matthews) correlation between every pair of pairs that share
// >= $minOverlap resolved trades on the SAME IST calendar date.
// Same-date co-movement is the practical definition of "same market move"
// for daily-file data at 5-min candles.

$minOverlapDays = 5; // minimum shared trading days to show a cell

// Build per-pair per-day outcome: pair -> date -> list of results
$pairDayOutcome = array();
foreach($rows as $row){
    $cls2 = $row['_cls'];
    if($cls2 !== 'win' && $cls2 !== 'loss') continue;
    $pr   = $row['pair_name'];
    $dt   = date('Y-m-d', $row['_ts']);
    if(!isset($pairDayOutcome[$pr][$dt])) $pairDayOutcome[$pr][$dt] = array();
    $pairDayOutcome[$pr][$dt][] = ($cls2 === 'win') ? 1 : 0;
}

// Average result per pair-day (a pair can fire more than once per day)
$pairDayAvg = array();
foreach($pairDayOutcome as $pr => $days){
    foreach($days as $dt => $vals){
        $pairDayAvg[$pr][$dt] = array_sum($vals)/count($vals);
    }
}

// Pairs with enough resolved trades to be worth correlating
$corrPairs = array();
foreach($pairWL as $pk => $d){
    if(($d['w']+$d['l']) >= 10) $corrPairs[] = $pk;
}
sort($corrPairs);
$nCorrPairs = count($corrPairs);

// Phi correlation between two binary sequences aligned by date
// Using day-average >= 0.5 as "win day" for the pair
function phiCorr(array $a, array $b): ?float {
    $shared = array_intersect(array_keys($a), array_keys($b));
    if(count($shared) < 3) return null;
    $tp=$fp=$fn=$tn=0;
    foreach($shared as $dt){
        $av = $a[$dt] >= 0.5 ? 1 : 0;
        $bv = $b[$dt] >= 0.5 ? 1 : 0;
        if($av&&$bv)        $tp++;
        elseif($av&&!$bv)   $fp++;
        elseif(!$av&&$bv)   $fn++;
        else                $tn++;
    }
    $denom = sqrt(($tp+$fp)*($tp+$fn)*($tn+$fp)*($tn+$fn));
    if($denom == 0) return null;
    return ($tp*$tn - $fp*$fn) / $denom;
}

// Count shared days between two pairs
function sharedDays(array $a, array $b): int {
    return count(array_intersect(array_keys($a), array_keys($b)));
}

// Build correlation grid
$corrMatrix = array(); // [pairA][pairB] = phi or null
for($i=0;$i<$nCorrPairs;$i++){
    for($j=0;$j<$nCorrPairs;$j++){
        $pa = $corrPairs[$i]; $pb = $corrPairs[$j];
        if($i === $j){ $corrMatrix[$pa][$pb] = 1.0; continue; }
        $shared = sharedDays(
            isset($pairDayAvg[$pa])?$pairDayAvg[$pa]:array(),
            isset($pairDayAvg[$pb])?$pairDayAvg[$pb]:array()
        );
        if($shared < $minOverlapDays){ $corrMatrix[$pa][$pb] = null; continue; }
        $corrMatrix[$pa][$pb] = phiCorr(
            isset($pairDayAvg[$pa])?$pairDayAvg[$pa]:array(),
            isset($pairDayAvg[$pb])?$pairDayAvg[$pb]:array()
        );
    }
}

// Strongly correlated pairs (|phi| >= 0.40, shared days >= minOverlapDays)
$strongPairs = array(); // array of [pairA, pairB, phi, sharedDays]
for($i=0;$i<$nCorrPairs;$i++){
    for($j=$i+1;$j<$nCorrPairs;$j++){
        $pa=$corrPairs[$i]; $pb=$corrPairs[$j];
        $phi=$corrMatrix[$pa][$pb];
        if($phi===null) continue;
        $sd=sharedDays(
            isset($pairDayAvg[$pa])?$pairDayAvg[$pa]:array(),
            isset($pairDayAvg[$pb])?$pairDayAvg[$pb]:array()
        );
        if(abs($phi)>=0.40) $strongPairs[]=array($pa,$pb,round($phi,3),$sd);
    }
}
usort($strongPairs,function($a,$b){ return abs($b[2])>abs($a[2])?1:-1; });

$pdf->SectionTitle('Pair / Signal Correlation Analysis',
    'Phi (Matthews) correlation of daily win/loss outcomes between pairs. Cells require >= '.$minOverlapDays.' shared trading days.',0);

if($nCorrPairs < 2){
    $pdf->SetFont('Arial','',8.5);
    $pdf->SetTextColor(150,156,168);
    $pdf->Cell(0,7,'Not enough pairs with 10+ resolved trades to compute correlations.',0,1,'L');
    $pdf->Ln(3);
} else {
    // Draw heatmap-style correlation grid
    $maxCells = min($nCorrPairs, 14);
    $displayPairs = array_slice($corrPairs,0,$maxCells);
    $cellS = min(11, 170/($maxCells+1));
    $labelW = max(18, $cellS*1.6);
    $gridX = 12 + $labelW;
    $gridY = $pdf->GetY() + 6;

    // Column headers
    $pdf->SetFont('Arial','B',5.5);
    $pdf->SetTextColor(100,108,122);
    foreach($displayPairs as $ci => $cp){
        $pdf->SetXY($gridX + $ci*$cellS, $gridY - 5);
        $pdf->Cell($cellS,4,substr($cp,0,6),0,0,'C');
    }

    foreach($displayPairs as $ri => $rp){
        // Row label
        $pdf->SetFont('Arial','B',6);
        $pdf->SetTextColor(60,68,82);
        $pdf->SetXY(12, $gridY + $ri*$cellS + ($cellS-4)/2);
        $pdf->Cell($labelW,4,$rp,0,0,'L');

        foreach($displayPairs as $ci => $cp){
            $phi = isset($corrMatrix[$rp][$cp]) ? $corrMatrix[$rp][$cp] : null;
            $cx = $gridX + $ci*$cellS;
            $cy = $gridY + $ri*$cellS;

            if($ri === $ci){
                // diagonal
                $pdf->SetFillColor(230,234,245);
            } elseif($phi === null){
                $pdf->SetFillColor(248,249,252);
            } else {
                // -1 = red, 0 = white, +1 = green
                if($phi >= 0){
                    $t = $phi;
                    $R = (int)(255 - 221*$t); $G = (int)(255 - 88*$t); $B = (int)(255 - 145*$t);
                } else {
                    $t = -$phi;
                    $R = (int)(255 - 31*$t); $G = (int)(255 - 188*$t); $B = (int)(255 - 176*$t);
                }
                $pdf->SetFillColor(max(0,min(255,$R)),max(0,min(255,$G)),max(0,min(255,$B)));
            }
            $pdf->Rect($cx+0.3,$cy+0.3,$cellS-0.6,$cellS-0.6,'F');

            if($phi !== null && $ri !== $ci){
                $pdf->SetFont('Arial','B',5);
                $absP = abs($phi);
                $pdf->SetTextColor($absP>=0.5?255:45, $absP>=0.5?255:52, $absP>=0.5?255:66);
                $pdf->SetXY($cx,$cy+($cellS-3.5)/2);
                $pdf->Cell($cellS,3.5,sprintf('%.2f',$phi),0,0,'C');
            }
        }
    }
    $pdf->SetY($gridY + count($displayPairs)*$cellS + 5);

    // Colour scale
    $scaleX = $gridX; $scaleW = min(80, $maxCells*$cellS);
    $steps = 30;
    for($si=0;$si<$steps;$si++){
        $p2 = ($si/($steps-1))*2 - 1; // -1 to +1
        if($p2>=0){ $t=$p2; $R=(int)(255-221*$t);$G=(int)(255-88*$t);$B=(int)(255-145*$t); }
        else { $t=-$p2; $R=(int)(255-31*$t);$G=(int)(255-188*$t);$B=(int)(255-176*$t); }
        $pdf->SetFillColor(max(0,min(255,$R)),max(0,min(255,$G)),max(0,min(255,$B)));
        $pdf->Rect($scaleX+$si*($scaleW/$steps),$pdf->GetY(),$scaleW/$steps+0.2,2.5,'F');
    }
    $pdf->SetFont('Arial','',6); $pdf->SetTextColor(130,138,150);
    $pdf->SetXY($scaleX-12,$pdf->GetY()-0.5); $pdf->Cell(11,4,'-1.0 (neg)',0,0,'R');
    $pdf->SetXY($scaleX+$scaleW+1,$pdf->GetY()-0.5); $pdf->Cell(20,4,'+1.0 (pos)',0,0,'L');
    $pdf->SetY($pdf->GetY()+7);

    // Strongly correlated pairs table
    if(count($strongPairs)){
        $pdf->SectionTitle('Highly Correlated Pairs  (|phi| >= 0.40)','These pairs tend to win or lose together — trading both simultaneously is not independent exposure.',count($strongPairs)*7+12);
        $scCols = array(array('Pair A',24,'L'),array('Pair B',24,'L'),array('Phi',18),array('Shared Days',26),array('Interpretation',98));
        $scRows = array();
        foreach($strongPairs as $sp){
            $phi = $sp[2];
            if($phi >= 0.60)       $interp = 'Strong positive — same market move, consider as one position';
            elseif($phi >= 0.40)   $interp = 'Moderate positive — partially correlated, reduces effective diversification';
            elseif($phi <= -0.60)  $interp = 'Strong negative — natural hedge, opposite outcomes';
            else                   $interp = 'Moderate negative — partial hedge';
            $phiCol = $phi>0?C_GREEN:C_RED;
            $scRows[] = array(
                array($sp[0],null,true), array($sp[1],null,true),
                array(sprintf('%+.3f',$phi),$phiCol,true),
                array((string)$sp[3]),
                array($interp),
            );
        }
        $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),$scCols,$scRows,7,8)+5);
    }

    $corrLines = array();
    $corrLines[] = "Phi ranges from -1 (always opposite outcomes) through 0 (independent) to +1 (always same outcome). A cell requires at least $minOverlapDays shared trading days to display.";
    $corrLines[] = "Pairs with phi >= 0.40 share enough co-movement that trading both simultaneously does NOT double your diversification — it amplifies your exposure to whatever drives them.";
    $corrLines[] = "Correlation is computed on daily win/loss outcomes, not price. Two pairs can be price-correlated but have different signal timing and therefore low phi.";
    if(count($strongPairs)>0){
        $top = $strongPairs[0];
        $corrLines[] = "Highest correlation in this period: ".$top[0]." vs ".$top[1]." at phi=".sprintf('%+.3f',$top[2])." over ".$top[3]." shared days.";
    }
    $pdf->Callout('READING THE CORRELATION MATRIX',$corrLines,C_NAVY);
}

// ── 2. SIMULTANEOUS SIGNAL ANALYSIS ──────────────────────────────────
$pdf->NeedSpace(20);
$pdf->SectionTitle('Simultaneous Signal Analysis',
    'Signals firing within the same 5-minute candle or within a configurable window are treated as a cluster. Measures whether clustering improves, hurts, or has no effect on outcomes.',0);

// Cluster signals that fire within $clusterWindow seconds of each other
$clusterWindowSec = 300; // same 5-min candle = same unix bucket
// Also show a wider window
foreach(array(300, 900, 1800) as $cwSec){
    $cwLabel = ($cwSec<60) ? $cwSec.'s' : round($cwSec/60).' min';

    // Sort all rows by timestamp
    $sortedRows = $rows;
    usort($sortedRows, function($a,$b){ return $a['_ts'] <=> $b['_ts']; });

    // Group into clusters
    $clusters = array();
    $curCluster = array();
    $clusterStart = null;
    foreach($sortedRows as $row){
        if($clusterStart === null){
            $curCluster = array($row); $clusterStart = $row['_ts'];
        } elseif($row['_ts'] - $clusterStart <= $cwSec){
            $curCluster[] = $row;
        } else {
            $clusters[] = $curCluster;
            $curCluster = array($row); $clusterStart = $row['_ts'];
        }
    }
    if($curCluster) $clusters[] = $curCluster;

    // Bucket clusters by size
    $sizeBuckets = array(
        '1 (solo)'   => array('signals'=>0,'w'=>0,'l'=>0,'p'=>0,'n'=>0),
        '2'          => array('signals'=>0,'w'=>0,'l'=>0,'p'=>0,'n'=>0),
        '3'          => array('signals'=>0,'w'=>0,'l'=>0,'p'=>0,'n'=>0),
        '4+'         => array('signals'=>0,'w'=>0,'l'=>0,'p'=>0,'n'=>0),
    );

    $clusterWinRates = array(); // for scatter / distribution
    $simultPairs    = array(); // pair combinations in clusters >= 2

    foreach($clusters as $cl){
        $sz = count($cl);
        $key = $sz>=4?'4+':($sz>=3?'3':($sz>=2?'2':'1 (solo)'));
        $sizeBuckets[$key]['signals'] += $sz;
        foreach($cl as $row){
            $cls2 = $row['_cls'];
            if($cls2==='win')          $sizeBuckets[$key]['w']++;
            elseif($cls2==='loss')     $sizeBuckets[$key]['l']++;
            elseif($cls2==='pending')  $sizeBuckets[$key]['p']++;
            else                       $sizeBuckets[$key]['n']++;
        }
        if($sz >= 2){
            // cluster-level: did ALL resolve the same way?
            $clWins = count(array_filter($cl,function($r){return $r['_cls']==='win';}));
            $clLoss = count(array_filter($cl,function($r){return $r['_cls']==='loss';}));
            $clRes  = $clWins + $clLoss;
            if($clRes>0) $clusterWinRates[] = $clWins/$clRes;

            // pair combos
            $pairsInCluster = array_unique(array_map(function($r){return $r['pair_name'];},$cl));
            sort($pairsInCluster);
            for($pi=0;$pi<count($pairsInCluster);$pi++){
                for($pj=$pi+1;$pj<count($pairsInCluster);$pj++){
                    $combo = $pairsInCluster[$pi].' + '.$pairsInCluster[$pj];
                    if(!isset($simultPairs[$combo])) $simultPairs[$combo]=0;
                    $simultPairs[$combo]++;
                }
            }
        }
    }

    $pdf->SetFont('Arial','B',9.5);
    $pdf->SetTextColor(25,42,86);
    $pdf->Cell(0,6,'Window: '.$cwLabel,0,1,'L');
    $pdf->Ln(1);

    // Stacked bar: win rate by cluster size
    $sizeWL = array();
    foreach($sizeBuckets as $lbl => $d){
        if(($d['w']+$d['l'])>0 || $d['signals']>0) $sizeWL[$lbl.' ('.$d['signals'].' signals)'] = array('w'=>$d['w'],'l'=>$d['l']);
    }
    if(array_sum(array_map(function($d){return $d['w']+$d['l'];},$sizeWL))>0){
        $endY2 = $pdf->StackedBar(12,$pdf->GetY(),186,$sizeWL,7,3.5,52,3,false,$breakeven);
        $pdf->SetY($endY2+2);
    }

    // Summary table
    $sumCols = array(array('Cluster Size',28,'L'),array('Clusters',22),array('Signals',22),
                     array('Wins',16),array('Losses',16),array('Win Rate',22),array('Net (u)',20));
    $sumRows = array();
    foreach($sizeBuckets as $lbl => $d){
        $res2 = $d['w']+$d['l'];
        // number of clusters = signals / cluster_size_approx — simpler: count directly
        $wr2  = $res2>0 ? round($d['w']/$res2*100,1) : null;
        $net2 = $d['w']*$payout - $d['l'];
        $wrC  = $wr2!==null ? (($wr2/100>=$breakeven+0.05)?C_GREEN:(($wr2/100<$breakeven)?C_RED:C_AMBER)) : C_GREY;
        $sumRows[] = array(
            array($lbl,null,true),
            array('-'), // cluster count not tracked separately; omit for clarity
            array((string)$d['signals']),
            array((string)$d['w'],C_GREEN,true),
            array((string)$d['l'],C_RED,true),
            array($wr2!==null?$wr2.'%':'-',$wrC,true),
            array($res2>0?sprintf('%+.2f',$net2):'-',$res2>0?($net2>=0?C_GREEN:C_RED):C_GREY),
        );
    }
    $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),$sumCols,$sumRows,6.5,7.5)+4);

    // Top co-occurring pairs
    if(count($simultPairs)>0){
        arsort($simultPairs);
        $topCombos = array_slice($simultPairs,0,8,true);
        $pdf->SetFont('Arial','B',7.5); $pdf->SetTextColor(45,52,66);
        $pdf->Cell(0,5,'Most frequent pair combos in '.$cwLabel.' clusters:',0,1,'L');
        $pdf->SetFont('Arial','',7.5); $pdf->SetTextColor(80,88,102);
        $lx2 = 12; $ly2 = $pdf->GetY()+1;
        foreach($topCombos as $combo => $cnt){
            $pdf->SetXY($lx2,$ly2);
            $pdf->Cell(80,4.5,$combo.':  '.$cnt.'x',0,0,'L');
            $lx2 += 80; if($lx2>150){ $lx2=12; $ly2+=4.5; }
        }
        $pdf->SetY($ly2+6);
    }
    $pdf->Ln(3);
}

// Interpretation callout
$simLines = array();
$simLines[] = "A 'cluster' is any group of signals that fire within the chosen window. Solo signals are the baseline. Clusters of 2+ test whether co-firing pairs behave differently.";
$simLines[] = "If cluster win rate >> solo win rate: simultaneous signals reinforce each other — consider treating them as confirmation.";
$simLines[] = "If cluster win rate << solo win rate: simultaneous signals may share a bad regime (correlated losers) — consider skipping one when another is already open.";
$simLines[] = "The 5-min window catches signals on the exact same candle. The 15-min and 30-min windows catch near-simultaneous signals that share the same macro catalyst.";
$simLines[] = "Pair combos show which specific combinations fire together most — cross-reference with the correlation matrix to see if those combos are also outcome-correlated.";
$pdf->Callout('SIMULTANEOUS SIGNAL INTERPRETATION',$simLines,C_TEAL,240,252,252);












/* ===== YEAR-BY-YEAR PERFORMANCE ===== */
$pdf->sectionName = 'Year-by-Year';
$pdf->AddPage();
$pdf->SetY(22);
$pdf->SectionTitle('Year-by-Year Performance','Win rate ± 95% CI per calendar year. Break-even at '.round($breakeven*100,1).'% highlighted.',24);

// Bucket every row by IST year
$yearStats = array();
foreach($rows as $row){
    $yr = date('Y', $row['_ts']);
    if(!isset($yearStats[$yr])) $yearStats[$yr] = array('t'=>0,'formed'=>0,'resolved'=>0,'w'=>0,'l'=>0);
    $yearStats[$yr]['t']++;
    $cls2 = $row['_cls'];
    if($cls2 !== 'notformed') $yearStats[$yr]['formed']++;
    if($cls2 === 'win' || $cls2 === 'loss'){
        $yearStats[$yr]['resolved']++;
        if($cls2 === 'win') $yearStats[$yr]['w']++; else $yearStats[$yr]['l']++;
    }
}
ksort($yearStats);

$yrCols = array(
    array('Year',16,'L'), array('Signals',20), array('Setup Formed',28),
    array('Resolved',22), array('Wins',16), array('Losses',16),
    array('Win Rate',22), array('95% CI',30), array('Net (u)',20), array('ROI',20),
);
$yrRows = array();
foreach($yearStats as $yr => $s){
    $wr2  = $s['resolved']>0 ? $s['w']/$s['resolved'] : 0;
    $ci2  = wilson($s['w'], $s['resolved']);
    $net2 = $s['w']*$payout - $s['l'];
    $roi2 = $s['resolved']>0 ? ($net2/$s['resolved'])*100 : 0;
    $fp2  = $s['t']>0 ? round($s['formed']/$s['t']*100) : 0;
    $wrCol  = ($wr2 >= $breakeven+0.05) ? C_GREEN : (($wr2 < $breakeven) ? C_RED : C_AMBER);
    $netCol = $net2 >= 0 ? C_GREEN : C_RED;
    $ciTxt  = $s['resolved']>0
        ? round($ci2['lo']*100,1).'% – '.round($ci2['hi']*100,1).'%'
        : '-';
    $wrTxt  = $s['resolved']>0 ? round($wr2*100,1).'%' : '-';
    $yrRows[] = array(
        array($yr, null, true),
        array((string)$s['t']),
        array($s['t']>0 ? $fp2.'%  ('.$s['formed'].')' : '-'),
        array((string)$s['resolved']),
        array((string)$s['w'], C_GREEN, true),
        array((string)$s['l'], C_RED,   true),
        array($wrTxt,  $wrCol,  true),
        array($ciTxt,  null,    false),
        array($s['resolved']>0 ? sprintf('%+.2f',$net2) : '-', $netCol, true),
        array($s['resolved']>0 ? sprintf('%+.1f%%',$roi2) : '-', $netCol, false),
    );
}
$pdf->SetY($pdf->DataTable(10,$pdf->GetY(),$yrCols,$yrRows,7,8)+6);

// Mini win-rate column chart per year
$yrTrend = array();
foreach($yearStats as $yr => $s){
    if($s['resolved']>0) $yrTrend[$yr] = round($s['w']/$s['resolved']*100,1);
}
if(count($yrTrend)>1){
    $pdf->SectionTitle('Annual Win Rate Trend','Red line = break-even '.round($breakeven*100,1).'%',42);
    $endY = $pdf->LineChart(22,$pdf->GetY()+3,172,34,$yrTrend,C_BLUE,$breakeven*100);
    $pdf->SetY($endY+4);
}

/* ===== ROLLING WIN RATE ===== */
$pdf->sectionName = 'Rolling Win Rate';
$pdf->AddPage();
$pdf->SetY(22);
$pdf->SectionTitle('Rolling Win Rate','Calculated over resolved trades only, sliding window. Shows whether edge is stable or drifting.',0);

// Build ordered resolved sequence with labels
$resolvedSeq = array(); // array of ['w'=>bool, 'ts'=>int, 'id'=>int]
foreach($rows as $row){
    if($row['_cls']==='win' || $row['_cls']==='loss'){
        $resolvedSeq[] = array('w'=>($row['_cls']==='win'), 'ts'=>$row['_ts'], 'id'=>(int)$row['raw_trade_id']);
    }
}
$nRes = count($resolvedSeq);

foreach(array(500,1000) as $window){
    if($nRes < $window){
        $pdf->SetFont('Arial','',8.5);
        $pdf->SetTextColor(150,156,168);
        $pdf->Cell(0,7,'Rolling '.$window.': only '.$nRes.' resolved trades available (need at least '.$window.').',0,1,'L');
        $pdf->Ln(2);
        continue;
    }

    $pdf->SectionTitle('Rolling '.$window.'-Trade Window','Each point = win rate of the most recent '.$window.' resolved trades at that position',48);

    // Sample ~20 evenly spaced points across the sequence
    $points = array(); $step2 = max(1,(int)floor(($nRes-$window)/19));
    for($start=0; $start+$window<=$nRes; $start+=$step2){
        $slice = array_slice($resolvedSeq,$start,$window);
        $wc = count(array_filter($slice,function($x){return $x['w'];}));
        $ci3 = wilson($wc,$window);
        $endIdx = $start+$window-1;
        $label = date('M y',$resolvedSeq[$endIdx]['ts']);
        $points[] = array('label'=>$label,'wr'=>round($wc/$window*100,1),'lo'=>round($ci3['lo']*100,1),'hi'=>round($ci3['hi']*100,1),'net'=>round($wc*$payout-($window-$wc),2),'end'=>$endIdx+1);
    }
    // ensure last window is always included
    $lastStart = $nRes-$window;
    $lastSlice = array_slice($resolvedSeq,$lastStart,$window);
    $lastWc = count(array_filter($lastSlice,function($x){return $x['w'];}));
    $lastCi = wilson($lastWc,$window);
    $lastLabel = 'Last '.($window >= 1000 ? ($window/1000).'k' : $window);
    $points[] = array('label'=>$lastLabel,'wr'=>round($lastWc/$window*100,1),'lo'=>round($lastCi['lo']*100,1),'hi'=>round($lastCi['hi']*100,1),'net'=>round($lastWc*$payout-($window-$lastWc),2),'end'=>$nRes);

    // Draw simple line chart for win rate
    $lineData = array();
    foreach($points as $pt) $lineData[$pt['label']] = $pt['wr'];
    $endY = $pdf->LineChart(22,$pdf->GetY()+3,172,34,$lineData,C_PURPLE,$breakeven*100);
    $pdf->SetY($endY+4);

    // Table below
    $rCols = array(array('Window ends at trade #',38,'L'),array('Label',24),array('Win Rate',22),array('CI Low',18),array('CI High',18),array('Above B/E?',24),array('Net (u)',20));
    $rRows = array();
    foreach($points as $pt){
        $abv = $pt['lo'] >= $breakeven*100;
        $wrC = $pt['wr'] >= $breakeven*100 ? C_GREEN : C_RED;
        $rRows[] = array(
            array('#'.$pt['end'],null,true),
            array($pt['label']),
            array($pt['wr'].'%',$wrC,true),
            array($pt['lo'].'%',$abv?C_GREEN:C_RED),
            array($pt['hi'].'%'),
            array($abv?'YES':'NO',$abv?C_GREEN:C_RED,true),
            array(sprintf('%+.2f',$pt['net']),$pt['net']>=0?C_GREEN:C_RED,true),
        );
    }
    $pdf->SetY($pdf->DataTable(10,$pdf->GetY(),$rCols,$rRows,6.5,7.5)+5);
}

/* ===== WALK-FORWARD TEST ===== */
$pdf->sectionName = 'Walk-Forward';
$pdf->AddPage();
$pdf->SetY(22);
$pdf->SectionTitle('Walk-Forward / Out-of-Sample Test',
    'Training = all data up to and including the training year. Test = the following calendar year only. Answers: is the edge still there in data the model never saw?',0);

// Collect resolved trades by year
$byYear = array();
foreach($resolvedSeq as $tr){
    $yr = date('Y',$tr['ts']);
    if(!isset($byYear[$yr])) $byYear[$yr] = array();
    $byYear[$yr][] = $tr;
}
$allYears = array_keys($byYear); sort($allYears);

$wfCols = array(
    array('Period',14,'L'), array('Training data',36), array('Test year',18),
    array('Test n',14), array('Test WR',18), array('95% CI',30),
    array('Net (u)',18), array('Above B/E?',22), array('Verdict',20),
);
$wfRows = array();
$wfLineData = array();

for($i=0;$i+1<count($allYears);$i++){
    $testYr = $allYears[$i+1];
    $trainYrs = array_slice($allYears,0,$i+1);
    $trainLabel = $trainYrs[0].($i>0?'–'.end($trainYrs):'');

    // training stats (for context)
    $trainSeq = array();
    foreach($trainYrs as $ty){ if(isset($byYear[$ty])) $trainSeq = array_merge($trainSeq,$byYear[$ty]); }

    // test stats
    $testSeq  = isset($byYear[$testYr]) ? $byYear[$testYr] : array();
    $testN    = count($testSeq);
    $testW    = count(array_filter($testSeq,function($x){return $x['w'];}));
    $testL    = $testN - $testW;
    $testWR   = $testN>0 ? $testW/$testN : 0;
    $testCi   = wilson($testW,$testN);
    $testNet  = $testW*$payout - $testL;
    $abvBE    = $testN>0 && $testCi['lo'] >= $breakeven;
    $wrC      = $testN>0 ? (($testWR >= $breakeven+0.05) ? C_GREEN : (($testWR < $breakeven) ? C_RED : C_AMBER)) : C_GREY;
    $netC     = $testNet >= 0 ? C_GREEN : C_RED;

    // verdict
    if($testN < 20)      $verdict = 'LOW N';
    elseif($abvBE)       $verdict = 'HOLDS';
    elseif($testCi['hi'] < $breakeven) $verdict = 'FAILS';
    else                 $verdict = 'MIXED';
    $verdCol = array('HOLDS'=>C_GREEN,'FAILS'=>C_RED,'MIXED'=>C_AMBER,'LOW N'=>C_GREY);

    $wfRows[] = array(
        array('WF-'.($i+1),null,true),
        array($trainLabel),
        array($testYr,null,true),
        array($testN>0?(string)$testN:'-'),
        array($testN>0?round($testWR*100,1).'%':'-', $wrC, true),
        array($testN>0?(round($testCi['lo']*100,1).'%–'.round($testCi['hi']*100,1).'%'):'-'),
        array($testN>0?sprintf('%+.2f',$testNet):'-', $netC, true),
        array($testN<1?'-':($abvBE?'YES':'NO'), $abvBE?C_GREEN:C_RED, true),
        array($verdict, $verdCol[$verdict], true),
    );

    if($testN >= 10) $wfLineData[$testYr] = round($testWR*100,1);
}

$pdf->SetY($pdf->DataTable(10,$pdf->GetY(),$wfCols,$wfRows,7,8)+5);

if(count($wfLineData)>1){
    $pdf->SectionTitle('Out-of-Sample Win Rate by Test Year','Red line = break-even. HOLDS = lower CI bound above break-even.',42);
    $endY = $pdf->LineChart(22,$pdf->GetY()+3,172,34,$wfLineData,C_TEAL,$breakeven*100);
    $pdf->SetY($endY+4);
}

// Interpretation callout
$holdsCount = count(array_filter($wfRows,function($r){return $r[8][0]==='HOLDS';}));
$failsCount = count(array_filter($wfRows,function($r){return $r[8][0]==='FAILS';}));
$totalWF    = count(array_filter($wfRows,function($r){return $r[8][0]!=='LOW N';}));
$wfLines = array();
$wfLines[] = "A HOLDS verdict means the out-of-sample test year had a lower CI bound above ".round($breakeven*100,1)."% - the edge was statistically present in unseen data.";
$wfLines[] = "A FAILS verdict means the CI upper bound was below break-even - the strategy was clearly unprofitable on that year's data.";
$wfLines[] = "A MIXED verdict means the point estimate was above break-even but the CI straddles it - not enough data to be certain either way.";
if($totalWF>0){
    $wfLines[] = "Your record: $holdsCount HOLDS, $failsCount FAILS, ".($totalWF-$holdsCount-$failsCount)." MIXED out of $totalWF evaluable walk-forward windows.";
    if($holdsCount === $totalWF){
        $wfLines[] = "Every evaluable year held up. That is strong evidence the edge is not confined to specific historical conditions.";
    } elseif($failsCount > $holdsCount){
        $wfLines[] = "More failures than holds. The aggregate win rate may be driven by a few good years, not a consistent edge.";
    }
}
$wfLines[] = "Caveat: annual windows are small. A single bad year can FAIL by chance even with a genuine edge. The rolling-window page gives a more granular picture.";
$pdf->Callout('HOW TO READ WALK-FORWARD RESULTS', $wfLines, C_NAVY);


            /* ===== TRADE LOG ===== */
            $pdf->sectionName = 'Trade Log';
            $pdf->AddPage();
            $pdf->SetY(22);
            $pdf->SectionTitle('Detailed Trade Log','Click VIEW to open the chart plotter for any signal',40);
            $pdf->SetY($pdf->InlineLegend(12,$pdf->GetY(),
                array('Win'=>C_GREEN,'Loss'=>C_RED,'Pending'=>C_AMBER,'No Setup'=>C_GREY)) + 3);

            $currentDay = '';
            $dTot=$dUp=$dDown=$dWin=$dLoss=0;
            $fill = false;

            $flushSummary = function() use (&$pdf,&$dTot,&$dUp,&$dDown,&$dWin,&$dLoss,$payout){
                $pdf->Ln(0.5);
                $pdf->SetFont('Arial','B',7.5);
                $pdf->SetTextColor(90,98,112);
                $rv = $dWin+$dLoss;
                $wr = $rv>0 ? '   |   Win rate '.round($dWin/$rv*100).'%   |   '.sprintf('%+.2f u',$dWin*$payout-$dLoss) : '';
                $pdf->Cell(0,5,"   Day total $dTot    CALL $dUp    PUT $dDown    W $dWin    L $dLoss".$wr,0,1,'L');
                $pdf->Ln(2.5);
            };

            foreach($rows as $row){
                $rid       = $row['raw_trade_id'];
                $pair      = $row['pair_name'];
                $target    = number_format($row['price_target'], 5);
                $direction = $row['_dir'];
                $rawResult = $row['trade_result'];
                $timeStr   = $row['_time'];
                $dateOnly  = $row['_date'];
                $session   = $row['_sess'];
                $cls       = $row['_cls'];

                $chartUrl = "$WebsiteURL/plot/plotter.php?search-id=" . urlencode($rid);

                if ($currentDay !== $dateOnly) {
                    if ($currentDay) $flushSummary();
                    $pdf->NeedSpace(38);

                    $pdf->SetFillColor(234,240,251);
                    $pdf->SetFont('Arial','B',9.5);
                    list($nr,$ng,$nb) = rgb(C_NAVY);
                    $pdf->SetTextColor($nr,$ng,$nb);
                    $pdf->Cell(0,7.5,"   $dateOnly",0,1,'L',true);

                    $pdf->SetFont('Arial','B',7.5);
                    $pdf->SetTextColor(255,255,255);
                    $pdf->SetFillColor($nr,$ng,$nb);
                    $pdf->SetDrawColor($nr,$ng,$nb);
                    foreach($pdf->headers as $i => $hh){
                        $pdf->Cell($pdf->widths[$i],6.8,$hh,1,0,'C',true);
                    }
                    $pdf->Ln();
                    $pdf->SetDrawColor(224,229,238);
                    $currentDay = $dateOnly;
                    $dTot=$dUp=$dDown=$dWin=$dLoss=0;
                    $fill = false;
                }

                $dTot++;
                if($direction==='CALL') $dUp++; else $dDown++;
                if($cls==='win') $dWin++; elseif($cls==='loss') $dLoss++;

                $pdf->SetFont('Arial','',7.5);
                $bgv = $fill?247:255;
                $pdf->SetFillColor($bgv,$bgv==255?255:250,$bgv==255?255:253);
                $pdf->SetTextColor(45,52,66);

                $pdf->Cell($pdf->widths[0],6.4,$rid,1,0,'C',true);

                list($br,$bg,$bb) = rgb(C_BLUE);
                $pdf->SetTextColor($br,$bg,$bb);
                $pdf->SetFont('Arial','U',7.5);
                $pdf->Cell($pdf->widths[1],6.4,"VIEW",1,0,'C',true,$chartUrl);

                $pdf->SetTextColor(45,52,66);
                $pdf->SetFont('Arial','B',7.5);
                $pdf->Cell($pdf->widths[2],6.4,$pair,1,0,'C',true);
                $pdf->SetFont('Arial','',7.5);
                $pdf->Cell($pdf->widths[3],6.4,$target,1,0,'C',true);

                if($direction==='CALL'){ list($dr,$dg,$db) = rgb(C_GREEN); } else { list($dr,$dg,$db) = rgb(C_RED); }
                $pdf->SetTextColor($dr,$dg,$db);
                $pdf->SetFont('Arial','B',7.5);
                $pdf->Cell($pdf->widths[4],6.4,$direction,1,0,'C',true);

                $pdf->SetTextColor(45,52,66);
                $pdf->SetFont('Arial','',7.5);
                $pdf->Cell($pdf->widths[5],6.4,$timeStr,1,0,'C',true);
                $pdf->SetTextColor(100,108,122);
                $pdf->Cell($pdf->widths[6],6.4,$session,1,0,'C',true);

                $map = array('win'=>array(C_GREEN,'WIN'),'loss'=>array(C_RED,'LOSS'),
                             'pending'=>array(C_AMBER,'PENDING'),'notformed'=>array(C_GREY,'NO SETUP'));
                $entry = isset($map[$cls]) ? $map[$cls] : array(C_GREY, strtoupper($rawResult));
                list($sr,$sg,$sb) = rgb($entry[0]);
                $pdf->SetTextColor($sr,$sg,$sb);
                $pdf->SetFont('Arial','B',7.5);
                $pdf->Cell($pdf->widths[7],6.4,$entry[1],1,1,'C',true);

                $fill = !$fill;
            }
            if($currentDay) $flushSummary();

            $pdf->Output('D', "Trade_Report_".$startDate."_to_".$endDate.".pdf");
        } else {
            echo "No trades found.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Trade Reports</title>
    <style>
        *{box-sizing:border-box;}
        body{font-family:'Segoe UI',system-ui,sans-serif;margin:0;min-height:100vh;
             background:radial-gradient(1200px 600px at 20% -10%,#1e3a8a 0%,transparent 60%),
                        linear-gradient(135deg,#0b1220 0%,#192a56 60%,#12203f 100%);
             display:flex;align-items:center;justify-content:center;padding:30px;}
        .card{background:#fff;padding:36px;border-radius:18px;
              box-shadow:0 24px 70px rgba(0,0,0,.42);max-width:470px;width:100%;}
        .badge{display:inline-block;background:#eef3ff;color:#3478f6;font-size:10.5px;
               font-weight:800;letter-spacing:1.4px;padding:6px 13px;border-radius:20px;margin-bottom:16px;}
        h2{margin:0 0 6px;color:#192a56;font-size:25px;letter-spacing:-.3px;}
        p.sub{margin:0 0 24px;color:#64748b;font-size:13px;line-height:1.5;}
        .rowg{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
        label{display:block;font-size:11px;font-weight:700;color:#475569;margin-top:12px;
              text-transform:uppercase;letter-spacing:.7px;}
        input{width:100%;padding:12px;margin-top:6px;border:1px solid #dde3ec;border-radius:9px;
              font-size:14px;background:#f8fafc;color:#1e293b;}
        input:focus{outline:none;border-color:#3478f6;background:#fff;box-shadow:0 0 0 3px rgba(52,120,246,.16);}
        button{width:100%;padding:15px;margin-top:26px;background:linear-gradient(90deg,#3478f6,#192a56);
               color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:15px;font-weight:700;letter-spacing:.3px;}
        button:hover{filter:brightness(1.14);}
        .quick{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;}
        .quick a{font-size:11.5px;color:#3478f6;background:#f1f5fd;padding:6px 11px;
                 border-radius:16px;text-decoration:none;font-weight:600;cursor:pointer;}
        .quick a:hover{background:#e3ecfd;}
        .feat{margin-top:24px;padding-top:18px;border-top:1px solid #eef1f6;
              display:grid;grid-template-columns:1fr 1fr;gap:9px;font-size:11.5px;color:#64748b;}
        .feat span::before{content:'';display:inline-block;width:6px;height:6px;border-radius:50%;
              background:#3478f6;margin-right:7px;vertical-align:middle;}
        p.be{margin:14px 0 0;font-size:12px;color:#475569;background:#f1f5fd;
             padding:9px 12px;border-radius:8px;border-left:3px solid #3478f6;}
        p.be b{color:#192a56;}
    </style>
</head>
<body>
    <div class="card">
        <div class="badge">ANALYTICS ENGINE</div>
        <h2>Generate Report</h2>
        <p class="sub">Binary options analytics with a full compounding study: ruin probability by ladder depth, martingale vs parlay vs flat, and a sample-adequacy gate on every pair.</p>
        <form method="post" id="f">
            <div class="rowg">
                <div><label>Start Date</label><input type="date" name="start_date" id="sd" required></div>
                <div><label>End Date</label><input type="date" name="end_date" id="ed" required></div>
            </div>
            <div class="rowg">
                <div>
                    <label>Payout %</label>
                    <input type="number" name="payout" id="po" value="80" min="1" max="500" step="0.5" required>
                </div>
                <div>
                    <label>Stake (optional)</label>
                    <input type="number" name="stake" value="" min="0" step="0.01" placeholder="e.g. 100">
                </div>
            </div>
            <p class="be">Break-even win rate: <b id="beOut">55.6%</b></p>
            <div class="rowg">
                <div><label>Max ruin %</label>
                    <input type="number" name="ruin_max" value="20" min="1" max="99" step="1"></div>
                <div><label>Horizon (trades)</label>
                    <input type="number" name="horizon" value="100" min="20" max="5000" step="10"></div>
            </div>
            <label>Monte Carlo runs</label>
            <input type="number" name="mc_runs" value="2000" min="200" max="20000" step="500">
            <div class="quick">
                <a onclick="setRange(7)">Last 7 days</a>
                <a onclick="setRange(30)">Last 30 days</a>
                <a onclick="setRange(90)">Last 90 days</a>
                <a onclick="setMonth()">This month</a>
            </div>
            <button type="submit">Download PDF Report</button>
        </form>
        <div class="feat">
            <span>Break-even win rate</span>
            <span>Payout sensitivity grid</span>
            <span>Equity curve in units</span>
            <span>Net units per pair</span>
            <span>Kelly stake sizing</span>
            <span>Wilson confidence bands</span>
            <span>Setup formation rate</span>
            <span>Currency attribution</span>
            <span>Weekday x hour heatmap</span>
            <span>CALL vs PUT bias</span>
            <span>Ladder capital tables</span>
            <span>Ruin by depth</span>
            <span>Streak vs chance</span>
            <span>Independence runs test</span>
            <span>Monte Carlo, 3 styles</span>
            <span>Per-pair sample gate</span>
        </div>
    </div>
<script>
function fmt(d){return d.toISOString().slice(0,10);}
function setRange(n){
    var e=new Date(), s=new Date(); s.setDate(s.getDate()-(n-1));
    document.getElementById('sd').value=fmt(s);
    document.getElementById('ed').value=fmt(e);
}
function setMonth(){
    var e=new Date(), s=new Date(e.getFullYear(),e.getMonth(),1);
    document.getElementById('sd').value=fmt(s);
    document.getElementById('ed').value=fmt(e);
}
function updBE(){
    var po = parseFloat(document.getElementById('po').value);
    var el = document.getElementById('beOut');
    if(!po || po<=0){ el.textContent = '-'; return; }
    var be = 100/(1+po/100);
    el.textContent = be.toFixed(1) + '%  (you need better than this to profit)';
}
document.getElementById('po').addEventListener('input', updBE);
setRange(30);
updBE();
</script>
</body>
</html>