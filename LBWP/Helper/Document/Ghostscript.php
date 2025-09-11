<?php

namespace LBWP\Helper\Document;

/**
 * Ghostscript Console Wrapper class
 * @package LBWP\Helper\Document
 * @author Michael Sebel <michael@comotive.ch
 */
class Ghostscript
{
  /**
   * Saves an image of the given pdf's page into $output path
   * @param $output
   * @param $input
   * @param int $quality
   * @param int $res
   * @param int $page
   * @return string the output file name for convenience
   */
  public static function generateImageFromPdf($output, $input, $quality = 95, $res = 150, $page = 1)
  {
    exec('gs' .
      ' -sDEVICE=jpeg' .
      ' -sOutputFile="' . $output . '"' .
      ' -dLastPage=' . $page .
      ' -dBATCH' .
      ' -dNOPAUSE' .
      ' -q' .
      ' -dNumRenderingThreads=4' .
      ' -dJPEGQ=' . $quality .
      ' -r' . $res . 'x' . $res .
      ' "' . $input . '"'
    );

    return $output;
  }

  /**
   * @param $output
   * @param $files
   * @return string the output file name for convenience
   */
  public static function mergePdfFiles($output, $files)
  {
    exec(
      'gs -dNOPAUSE -sDEVICE=pdfwrite -sOUTPUTFILE=' . $output . ' -dBATCH ' . implode(' ', $files)
    );

    return $output;
  }

  /**
   * @param string $file the file
   * @return int the number of pages or zero
   */
  public static function countPdfPages($file)
  {
    return intval(shell_exec(
      'gs -q -dNODISPLAY -c "(' . $file . ') (r) file runpdfbegin pdfpagecount = quit"'
    ));
  }
}