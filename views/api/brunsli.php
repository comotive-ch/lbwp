<?php

use LBWP\Core as LbwpCore;
use LBWP\Module\General\Cms\SystemLog;
use LBWP\Util\File;
use LBWP\Util\AwsFactoryV3;
use LBWP\Module\Backend\S3Upload;

require_once '../../../../../wp-load.php';

$data = json_decode(file_get_contents('php://input'), true);

$base = LbwpCore::getCdnFileUri();
$original = $base . '/' . $data['reference'];
// Download the converted file locally
$folder = File::getNewUploadFolder();
$filepath = $folder . File::getFileOnly($data['result']);
file_put_contents($filepath, file_get_contents($data['result']));
$s3 = AwsFactoryV3::getS3Service();

try {
  $result = $s3->putObject(array(
    'SourceFile' => $filepath,
    'ACL' => S3Upload::ACL_PUBLIC,
    'CacheControl' => 'max-age=' . (315360000),
    'ContentType' => 'image/jpeg',
    'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 315360000),
    'Bucket' => CDN_BUCKET_NAME,
    'Key' => str_replace(LbwpCore::getCdnProtocol() . '://' . LbwpCore::getCdnName() . '/', '', $original)
  ));
} catch (\Exception $e) {
  SystemLog::add('CdnUpload', 'error', 'Brunsli Replace Error: ' . $e->getMessage());
}

// Remove the local file
unlink($filepath);