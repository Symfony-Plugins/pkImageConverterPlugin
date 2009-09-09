<?php

/*
 *
 * Efficient image conversions using netpbm or (if netpbm is not available) gd.
 * For more information see the README file.
 *
 */ 

class pkImageConverter 
{
  // Produces images suitable for intentional cropping by CSS.
  // Either the width or the height will match the request; the other
  // will EXCEED the request. Looks nicer than letterboxing in cases
  // where keeping the entire picture is not essential.

  static public function scaleToNarrowerAxis($fileIn, $fileOut, $width, $height, $quality = 75)
  {
    $width = ceil($width);
    $height = ceil($height);
    $quality = ceil($quality);
    list($iwidth, $iheight) = getimagesize($fileIn); 
    if (!$iwidth) {
      return false;
    }
    $iratio = $iwidth / $iheight;
    $ratio = $width / $height;
    if ($iratio > $ratio) {
      $width = false;
    } else {
      $height = false;
    }
    return self::scaleToFit($fileIn, $fileOut, $width, $height, $quality);
  }

  static public function scaleToFit($fileIn, $fileOut, 
    $width, $height, $quality = 75)
  {
    if ($width === false) {
      $scaleParameters = array('ysize' => $height + 0);
    } elseif ($height === false) {
      $scaleParameters = array('xsize' => $width + 0);
    } else {
      $scaleParameters = array('xysize' => array($width + 0, $height + 0));
    }
    $result = self::scaleBody($fileIn, $fileOut, $scaleParameters, array(), $quality);
    return $result;
  }

  static public function scaleByFactor($fileIn, $fileOut, $factor, 
    $quality = 75)
  {
    $quality = ceil($quality);
    $scaleParameters = array('scale' => $factor + 0);  
    return self::scaleBody($fileIn, $fileOut, $scaleParameters, array(), $quality);
  }

  static public function cropOriginal($fileIn, $fileOut, $width, $height,
    $quality = 75)
  {
    $width = ceil($width);
    $height = ceil($height);
    $quality = ceil($quality);
    list($iwidth, $iheight) = getimagesize($fileIn); 
    if (!$iwidth) 
    {
      return false;
    }
    $iratio = $iwidth / $iheight;
    $ratio = $width / $height;

    $scale = array('xysize' => array($width + 0, $height + 0));
    if ($iratio < $ratio)
    {
      $cropHeight = floor($iwidth * ($height / $width));
      $cropTop = floor(($iheight - $cropHeight) / 2);
      $cropLeft = 0;
      $cropWidth = $iwidth;
    }
    else
    {
      $cropWidth = floor($iheight * $ratio);
      $cropLeft = floor(($iwidth - $cropWidth) / 2);
      $cropTop = 0;
      $cropHeight = $iheight;
    }
    $scale = array('xysize' => array($width + 0, $height + 0));
    $crop = array('left' => $cropLeft, 'top' => $cropTop, 'width' => $cropWidth, 'height' => $cropHeight);
    return self::scaleBody($fileIn, $fileOut, $scale, $crop, $quality);
  }

  // Change the format without cropping or scaling
  static public function convertFormat($fileIn, $fileOut, $quality = 75)
  {
    $quality = ceil($quality);
    return self::scaleBody($fileIn, $fileOut, false, false, $quality);
  }

  static private function scaleBody($fileIn, $fileOut, 
    $scaleParameters = false, $cropParameters = false, $quality = 75) 
  {    
    if ($scaleParameters === false)
    {
      $scaleParameters = array();
    }
    if ($cropParameters === false)
    {
      $cropParameters = array();
    }
    if (sfConfig::get('app_pkimageconverter_netpbm', false))
    {
      $outputFilters = array(
        "jpg" => "pnmtojpeg --quality %d",
        "jpeg" => "pnmtojpeg --quality %d",
        "ppm" => "cat",
        "pbm" => "cat",
        "pgm" => "cat",
        "tiff" => "pnmtotiff",
        "png" => "pnmtopng",
        "gif" => "ppmquant 256 | ppmtogif",
        "bmp" => "ppmtobmp"
      );
      if (preg_match("/\.(\w+)$/", $fileOut, $matches)) {
        $extension = $matches[1];
        $extension = strtolower($extension);
        if (!isset($outputFilters[$extension])) {
          return false;
        }
        $filter = sprintf($outputFilters[$extension], $quality);
      } else {
        return false;
      }
      $path = sfConfig::get("app_pkimageconverter_path", "");
      if (strlen($path)) {
        if (!preg_match("/\/$/", $path)) {
          $path .= "/";
        }
      }
      $input = 'anytopnm';
      if (preg_match("/\.pdf$/", $fileIn))
      {
        $input = 'gs -sDEVICE=ppm -sOutputFile=- ' .
          ' -dNOPAUSE -dFirstPage=1 -dLastPage=1 -r100 -q -';
      }
      $scaleString = '';
      $extraInputFilters = '';
      foreach ($scaleParameters as $key => $values)
      {
        $scaleString .= " -$key ";
        if (is_array($values))
        {
          foreach ($values as $value)
          {
            $value = ceil($value);
            $scaleString .= " $value";
          }
        }
        else
        {
          $values = ceil($values);
          $scaleString .= " $values";
        }
      }
      if (count($cropParameters))
      {
        $extraInputFilters = 'pnmcut ';
        foreach ($cropParameters as $ckey => $cvalue)
        {
          $cvalue = ceil($cvalue);
          $extraInputFilters .= " -$ckey $cvalue";
        }
      }
      
      $cmd = "(PATH=$path:\$PATH; export PATH; $input < " . escapeshellarg($fileIn) . " " . ($extraInputFilters ? "| $extraInputFilters" : "") . " " . ($scaleParameters ? "| pnmscale $scaleString " : "") . "| $filter " .
        "> " . escapeshellarg($fileOut) . " " .
        ") 2> /dev/null";
      sfContext::getInstance()->getLogger()->info("$cmd");
      system($cmd, $result);
      if ($result != 0) 
      {
        return false;
      }
      return true;
    }
    else
    {
      // gd version for those who can't install netpbm, poor buggers
      // does not support PDF (if you can install ghostview, you can install netpbm)
      $in = self::imagecreatefromany($fileIn);
      $top = 0;
      $left = 0;
      $width = imagesx($in);
      $height = imagesy($in);
      if (count($cropParameters))
      {
        if (isset($cropParameters['top']))
        {
          $top = $cropParameters['top'];
        }
        if (isset($cropParameters['left']))
        {
          $left = $cropParameters['left'];
        }
        if (isset($cropParameters['width']))
        {
          $width = $cropParameters['width'];
        }
        if (isset($cropParameters['height']))
        {
          $height = $cropParameters['height'];
        }
        $cropped = imagecreatetruecolor($width, $height);
        imagecopy($cropped, $in, 0, 0, $left, $top, $width, $height);
        imagedestroy($in);
        $in = null;
      }
      else
      {
        // No cropping, so don't waste time and memory
        $cropped = $in;
        $in = null;
      }
    
      if (count($scaleParameters))
      {
        $width = imagesx($cropped);
        $height = imagesy($cropped);
        $swidth = $width;
        $sheight = $height;
        if (isset($scaleParameters['xsize']))
        {
          $height = $scaleParameters['xsize'] * imagesy($cropped) / imagesx($cropped);
          $width = $scaleParameters['xsize'];
          $out = imagecreatetruecolor($width, $height);
          imagecopyresampled($out, $cropped, 0, 0, 0, 0, $width, $height, imagesx($cropped), imagesy($cropped));
          imagedestroy($cropped);
          $cropped = null;
        }
        elseif (isset($scaleParameters['ysize']))
        {
          $width = $scaleParameters['ysize'] * imagesx($cropped) / imagesy($cropped);
          $height = $scaleParameters['ysize'];
          $out = imagecreatetruecolor($width, $height);
          imagecopyresampled($out, $cropped, 0, 0, 0, 0, $width, $height, imagesx($cropped), imagesy($cropped));
          imagedestroy($cropped);
          $cropped = null;
        }
        elseif (isset($scaleParameters['scale']))
        {
          $width = imagesx($cropped) * $scaleParameters['scale'];
          $height = imagesy($cropped)* $scaleParameters['scale'];
          $out = imagecreatetruecolor($width, $height);
          imagecopyresampled($out, $cropped, 0, 0, 0, 0, $width, $height, imagesx($cropped), imagesy($cropped));
          imagedestroy($cropped);
          $cropped = null;
        }
        elseif (isset($scaleParameters['xysize']))
        {
          $width = $scaleParameters['xysize'][0];
          $height = $scaleParameters['xysize'][1];
          $out = imagecreatetruecolor($width, $height);
          // This is the tricky bit
          if (($width / $height) > ($swidth / $sheight))
          {
            // Wider than the original. Black bars left and right        
            $iwidth = ceil($swidth * ($height / $sheight));
            imagecopyresampled($out, $cropped, ($width - $iwidth) / 2, 0, 0, 0, 
              $iwidth, $height, $swidth, $sheight);
            imagedestroy($cropped);
            $cropped = null;
          }
          else
          {
            // Narrower than the original. Letterboxing (bars top and bottom)
            $iheight = ceil($sheight * ($width / $swidth));
            imagecopyresampled($out, $cropped, 0, ($height - $iheight) / 2, 0, 0, 
              $width, $iheight, $swidth, $sheight);
            imagedestroy($cropped);
            $cropped = null;
          }
        }
      }
      else
      {
        // No scaling, don't waste time and memory
        $out = $cropped;
        $cropped = null;
      }

      if (preg_match("/\.(\w+)$/i", $fileOut, $matches))
      {
        $extension = $matches[1];
        $extension = strtolower($extension);
        if ($extension === 'gif')
        {
          imagegif($out, $fileOut);
        }
        elseif (($extension === 'jpg') || ($extension === 'jpeg'))
        {
          imagejpeg($out, $fileOut, $quality);
        }
        elseif ($extension === 'png')
        {
          imagepng($out, $fileOut);
        }
        else
        {
          return false;
        }
      }
      imagedestroy($out);
      $out = null;
      return true;
    }
  }

  // Odds and ends missing from gd
  
  // As commonly found on the Internets

  static private function imagecreatefromany($filename) 
  {
    foreach (array('png', 'jpeg', 'gif', 'bmp', 'ico') as $type) 
    {
      $func = 'imagecreatefrom' . $type;
      if (is_callable($func)) 
      {
        $image = @call_user_func($func, $filename);
        if ($image) return $image;
      }
    }
    return false;
  }

}

?>
