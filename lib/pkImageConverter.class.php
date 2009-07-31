<?php

/*
 *
 * netpbm-based image conversions that don't run the risk of bumping
 * into PHP's memory limit. These use scanline-based command line
 * utilities that never allocate a two-dimensional image in memory.
 * This makes importing huge JPEGs practical. 
 *
 * The netpbm utilities must be installed. Good hosts have them,
 * others can easily install them ('apt-get install netpbm',
 * 'yum install netpbm-progs', etc. as appropriate to the OS). They are
 * available for MacOS X here:
 *
 * http://netpbm.darwinports.com/
 *
 * Even after you do that, though, they probably won't be in 
 * the PATH environment variable for MAMP servers. So type:
 *
 * which ppmtogif
 *
 * Make a note of the folder it's in, and set things up in app.yml
 * to help pkImageConverter find the programs:
 *
 * all:
 *   pkimageconverter:
 *     path: /wherever/they/are
 *
 * Be sure to do a symfony cc and the tools should now be found.
 *
 * This code probably won't work without modification on a Windows host.
 *
 * Usage:
 *
 * Scale to largest size that does not exceed a width of 400 pixels or a
 * height of 300 pixels, but preserve aspect ratio, so the final image
 * will not be exactly 400x300 unless the original has a 4/3 ratio:
 *
 * pkImageConverter::scaleToFit("inputfile.jpg", "outputfile.jpg", 400, 300);
 *
 * If width or height is false (not zero), the other parameter will be
 * honored exactly and the mising parameter will be scaled accordingly to 
 * preserve the aspect ratio.
 *
 * Or to produce an image which is 50% of original size:
 *
 * pkImageConverter::scaleByFactor("inputfile.jpg", "outputfile.jpg", 0.5);
 *
 * Sometimes preserving the entire input image is not as important as
 * producing a copy with a certain aspect ratio. To scale and crop at
 * the same time, taking the largest portion of the center of the original
 * image that scales without distortion into the desired destination image:
 *
 * pkImageConverter::cropOriginal("inputfile.jpg", "outputfile.jpg",
 *   600, 450);
 *
 * The resulting image will be exactly 600x450 pixels, even if this requires
 * leaving out part of the original.
 *
 * One more: scaleToNarrowerAxis. scaleToNarrowerAxis produces output
 * images in which either the width or height will match the request and
 * the other dimension will EXCEED the request (unless the aspect ratio
 * of the original is exactly the same as the destination). This is handy
 * for creating images that you intend to crop with CSS. The result is
 * prettier than letterboxing.

 * pkImageConverter::scaleToNarrowerAxis("inputfile.jpg", "outputfile.jpg",
     600, 450); 
 *
 * An optional JPEG quality argument may be specified as the final argument
 * to all of these functions. The quality argument is ignored for other 
 * output formats.
 * 
 * The input file does not have to be in JPEG format. In fact, it can be
 * in just about any format, certainly GIF, JPEG, PNG, TIFF and BMP.
 *
 * The output file can be in gif, jpeg, tiff, bmp, png, ppm, pbm, or pgm
 * format (netpbm supports more, this is just what I've had time to list
 * in the output filter array here). 
 * 
 * The output file format is determined by the file extension. The input
 * file format is determined by the 'file' command, which looks at the
 * actual content of the file. This means you can convert a file uploaded
 * to PHP without renaming it first.
 *
 * Due to the use of system() and the piping of noisy messages from
 * netpbm to /dev/null this code will not work without modification
 * on Windows systems. 
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
    $width = ceil($width);
    $height = ceil($height);
    $quality = ceil($quality);
    if ($width === false) {
      $scaleParameters = "-ysize " . ($height + 0);
    } elseif ($height === false) {
      $scaleParameters = "-xsize " . ($width + 0);
    } else {
      $scaleParameters = "-xysize " . ($width + 0) . " " . ($height + 0);
    }
    return self::scaleBody($fileIn, $fileOut, $scaleParameters, $quality);
  }

  static public function scaleByFactor($fileIn, $fileOut, $factor, 
    $quality = 75)
  {
    $quality = ceil($quality);
    $scaleParameters = $factor + 0;  
    return self::scaleBody($fileIn, $fileOut, $scaleParameters, $quality);
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

    sfContext::getInstance()->getLogger()->info("iratio $iratio ratio $ratio\n");
    $scale = "-xysize " . ($width + 0) . " " . ($height + 0); 
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
    return self::scaleBody($fileIn, $fileOut, $scale, $quality,
      "pnmcut -left $cropLeft -top $cropTop -width $cropWidth -height $cropHeight");
  }

  static private function scaleBody($fileIn, $fileOut, 
    $scaleParameters, $quality = 75, $extraInputFilters = false) 
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
    $cmd = "(PATH=$path:\$PATH; export PATH; $input < " . escapeshellarg($fileIn) . " " . ($extraInputFilters ? "| $extraInputFilters" : "") . " " . ($scaleParameters ? "| pnmscale $scaleParameters " : "") . "| $filter " .
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

  // Change the format without cropping or scaling
  static public function convertFormat($fileIn, $fileOut, $quality = 75)
  {
    $quality = ceil($quality);
    return self::scaleBody($fileIn, $fileOut, false, $quality);
  }
}

?>
