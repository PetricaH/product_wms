<?php
/**
 * FPDF extension with rotation support
 * Save this as: /lib/fpdf_rotation.php
 */

require_once 'fpdf.php';

class PDF_Rotate extends FPDF
{
    var $angle = 0;

    function Rotate($angle, $x=-1, $y=-1)
    {
        if($x==-1)
            $x=$this->x;
        if($y==-1)
            $y=$this->y;
        if($this->angle!=0)
            $this->_out('Q');
        $this->angle=$angle;
        if($angle!=0)
        {
            $angle*=M_PI/180;
            $c=cos($angle);
            $s=sin($angle);
            $cx=$x*$this->k;
            $cy=($this->h-$y)*$this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',$c,$s,-$s,$c,$cx,$cy,-$cx,-$cy));
        }
    }

    function _endpage()
    {
        if($this->angle!=0)
        {
            $this->angle=0;
            $this->_out('Q');
        }
        parent::_endpage();
    }
}

/**
 * Convenience class for rotated text
 */
class PDF_RotatedText extends PDF_Rotate
{
    function RotatedText($x, $y, $txt, $angle)
    {
        //Text rotated around its origin
        $this->Rotate($angle, $x, $y);
        $this->Text($x, $y, $txt);
        $this->Rotate(0);
    }

    function RotatedCell($w, $h, $txt, $border=0, $ln=0, $align='', $fill=false, $angle=0)
    {
        //Cell rotated around its center
        $this->Rotate($angle, $this->GetX() + $w/2, $this->GetY() + $h/2);
        $this->Cell($w, $h, $txt, $border, $ln, $align, $fill);
        $this->Rotate(0);
    }
}