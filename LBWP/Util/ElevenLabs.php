<?php

namespace LBWP\Util;

use LBWP\Core;
use LBWP\Module\Backend\S3Upload;
use LBWP\Module\General\Cms\SystemLog;

/**
 * Class to convert text into audio using the ElevenLabs API
 * Also creates timestamps for the generated audio if needed
 */
class ElevenLabs
{

  protected $apiKey = '';
  protected $audioFile = '';
  protected $timestampFile = '';
  protected $timestampJson = '';
  protected $lastText = '';
  protected $interpunctations = array('.', ',', '?', '!', ';');
  protected $voiceSettings = array();

  public function __construct($apiKey)
  {
    $this->apiKey = $apiKey;
  }

  /**
   * @param $text
   * @param $voiceId
   * @param $timestamps
   * @param $filename
   * @return string
   * @throws \Exception
   */
  public function generateAudio($text, $voiceId, $timestamps = false, $filename = 'audio')
  {
    // Create chunks of text
    $chunks = $this->splitTextIntoChunks($text, 3000);

    $path = File::getNewUploadFolder();
    $mp3Files = [];
    $allTimestamps = [
      'characters' => [],
      'character_start_times_seconds' => [],
      'character_end_times_seconds' => []
    ];
    $currentTimeOffset = 0;

    foreach ($chunks as $index => $chunk) {
      $chunkFilename = $filename . '_part' . ($index + 1);
      $this->audioFile = $path . $chunkFilename . '.mp3';
      $this->timestampFile = $path . $chunkFilename . '.json';

      $url = 'https://api.elevenlabs.io/v1/text-to-speech/' . $voiceId;
      if ($timestamps) {
        $url .= '/with-timestamps';
      }

      $data = [
        'text' => $chunk,
        'model_id' => 'eleven_multilingual_v2'
      ];
      if (count($this->voiceSettings) > 0) {
        $data['voice_settings'] = $this->voiceSettings;
      }

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 300);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'xi-api-key: ' . $this->apiKey,
        'Content-Type: application/json',
      ]);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
      $response = curl_exec($ch);

      if (curl_errno($ch)) {
        SystemLog::add('ElevenLabs', 'error', 'CURL error on retrieving audio: ' . curl_error($ch), $response);
        curl_close($ch);
        return false;
      }

      curl_close($ch);
      $responseData = json_decode($response, true);

      if (isset($responseData['audio_base64'])) {
        file_put_contents($this->audioFile, base64_decode($responseData['audio_base64']));
        $mp3Files[] = $this->audioFile;
      } else {
        SystemLog::add('ElevenLabs', 'error', 'Could not retrieve audio data.', $responseData);
        return false;
      }

      if ($timestamps && isset($responseData['alignment'])) {
        $alignment = $this->convertNewlines($responseData['alignment']);
        foreach ($alignment['character_start_times_seconds'] as &$start) {
          $start += $currentTimeOffset;
        }
        foreach ($alignment['character_end_times_seconds'] as &$end) {
          $end += $currentTimeOffset;
        }
        $currentTimeOffset = end($alignment['character_end_times_seconds']);
        // Add data to the allTimestamps array
        $allTimestamps['characters'] = array_merge($allTimestamps['characters'], $alignment['characters']);
        $allTimestamps['character_start_times_seconds'] = array_merge($allTimestamps['character_start_times_seconds'], $alignment['character_start_times_seconds']);
        $allTimestamps['character_end_times_seconds'] = array_merge($allTimestamps['character_end_times_seconds'], $alignment['character_end_times_seconds']);
      }
    }

    // Concat files if more than one
    $finalMp3File = $path . $filename . '.mp3';
    if ($mp3Files > 1) {
      $this->concatenateMp3Files($mp3Files, $finalMp3File);
    } else {
      // just rename the one file to the final name
      rename($mp3Files[0], $finalMp3File);
    }

    // JSON-Timestamps speichern
    if ($timestamps) {
      $finalTimestampFile = $path . $filename . '.json';
      file_put_contents($finalTimestampFile, json_encode($allTimestamps));
      $this->timestampJson = $allTimestamps;
    }

    $this->audioFile = $finalMp3File;
    return $this->audioFile;
  }

  /**
   * @param $text
   * @param $chunkSize
   * @return array
   */
  protected function splitTextIntoChunks($text, $chunkSize = 5000)
  {
    $chunks = [];
    $offset = 0;

    while ($offset < strlen($text)) {
      $chunk = substr($text, $offset, $chunkSize);
      $breakPoint = strrpos($chunk, '.');
      // When breakpoint is found or end
      if ($breakPoint === false || $offset + $chunkSize >= strlen($text)) {
        $breakPoint = strlen($chunk);
      }
      // Add the chunk
      $chunks[] = substr($chunk, 0, $breakPoint + 1);
      $offset += $breakPoint + 1;
    }

    return array_map('trim', $chunks);
  }

  /**
   * @param $mp3Files
   * @param $outputFile
   * @return void
   * @throws \Exception
   */
  protected function concatenateMp3Files($mp3Files, $outputFile)
  {
    // create temporary file
    $fileList = tempnam(sys_get_temp_dir(), 'mp3_list');
    $handle = fopen($fileList, 'w');
    foreach ($mp3Files as $file) {
      fwrite($handle, "file '" . $file . "'\n");
    }
    fclose($handle);

    // glue files together with ffmpeg
    $command = "ffmpeg -f concat -safe 0 -i " . escapeshellarg($fileList) . " -c copy " . escapeshellarg($outputFile) . " 2>&1";
    $output = shell_exec($command);
    unlink($fileList);

    // check if the file is created correctly
    if (!file_exists($outputFile)) {
      SystemLog::add('ElevenLabs', 'error', 'Could not concatenate mp3 files.', $output);
    }

    return $outputFile;
  }

  /**
   * @param array $alignment
   * @return void
   */
  protected function convertNewlines($alignment)
  {
    // in the characters array, replace every single new line entry with PHP_EOL string
    foreach ($alignment['characters'] as $index => $char) {
      if ($char === "\n" || $char == PHP_EOL) {
        $alignment['characters'][$index] = 'PHP_EOL'; // literally the string so we can work with it later
      }
    }

    // Now go trough the characters array, and remove every consecituve PHP_EOL, so we have at max one PHP_EOL in a row
    $lastChar = '';
    $newChars = array();
    $newStarts = array();
    foreach ($alignment['characters'] as $index => $char) {
      if ($char === 'PHP_EOL' && $lastChar === 'PHP_EOL') {
        continue;
      }
      $newChars[] = $char;
      $newStarts[] = $alignment['character_start_times_seconds'][$index];
      $newEnds[] = $alignment['character_end_times_seconds'][$index];
      $lastChar = $char;
    }
    $alignment['characters'] = $newChars;
    $alignment['character_start_times_seconds'] = $newStarts;
    $alignment['character_end_times_seconds'] = $newEnds;

    // Lastly, convert the PHP_EOL to a . to simulate an interpunctation
    foreach ($alignment['characters'] as $index => $char) {
      if ($char === 'PHP_EOL') {
        $alignment['characters'][$index] = '.';
      }
    }

    return $alignment;
  }

  /**
   * @param $settings
   * @return void
   */
  public function setVoiceSettings($settings)
  {
    $this->voiceSettings = $settings;
  }

  /**
   * @return string url of the mp3 file
   */
  public function moveAudioToBlockStorage()
  {
    /** @var S3Upload $s3 */
    $s3 = Core::getModule('S3Upload');
    return $s3->uploadDiskFile($this->audioFile, 'audio/mp3');
  }

  /**
   * @return string json for the timestamps f generated
   */
  public function getTimestamps()
  {
    return $this->timestampJson;
  }

  /**
   * Convert the character json to a by sencence
   * @return void
   */
  public function convertToWhisperTimestamps($isArray)
  {
    $start = 0;
    $end = 0;
    $parts = $starts = $ends = array();
    $timestamps = $this->timestampJson;
    if (!$isArray) {
      $timestamps = array($this->timestampJson);
    }

    // Split the 'characters' array into sentences devided by end of the interpunctations
    $sentence = array();
    foreach ($timestamps as $timestampKey => $timestampPart) {
      foreach ($timestampPart['characters'] as $index => $char) {
        if (in_array($char, $this->interpunctations)) {
          $parts[] = $sentence;
          $starts[] = $start;
          $ends[] = $end;
          $sentence = array();
          $start = $end;
        } else {
          $sentence[] = $char;
          $end = $timestampPart['character_end_times_seconds'][$index];
        }
      }

      $segments = array();
      foreach ($parts as $index => $characters) {
        $text = trim(implode('', $characters));
        // Prevent empty segments in by word mode
        if (strlen($text) > 0) {
          $segments[] = array(
            'id' => ($index + 1),
            'start' => $starts[$index],
            'end' => $ends[$index],
            'text' => $text
          );
        }
      }

      $result = array(
        'segments' => $segments
      );

      $timestamps[$timestampKey] = $result;
    }

    if ($isArray) {
      $this->timestampJson = $timestamps;
    } else {
      $this->timestampJson = $timestamps[0];
    }
  }
}