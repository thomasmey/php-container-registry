<?php

function error($code, $message, $detail) {
   printf('{"errors": [ {"code": "%s", "message": "%s", "detail": "%s"} ] }', $code, $message, $detail);
}

function startUpload($name) {
  $date = new DateTime('now');
  $uuid = bin2hex(random_bytes(16));
  $hc = hash_init('sha256');

  $uploadMeta = [ 
     "createdAt" => $date->format(DateTime::ISO8601),
     "uuid" => $uuid,
     "name" => $name,
     "hc" => bin2hex(serialize($hc))
  ];

  $documentRoot = getenv('DOCUMENT_ROOT');
  $fileName = "$documentRoot/v2/blobs/uploads/$uuid";
  file_put_contents($fileName . ".json", json_encode($uploadMeta));

  header("Location: /v2/$name/blobs/uploads/$uuid");
  header("Content-Length: 0");
  header("Docker-Upload-UUID: $uuid");
  http_response_code(202);
}

function patchUpload($name, $uuid) {
  $documentRoot = getenv('DOCUMENT_ROOT');
  $fileName = "$documentRoot/v2/blobs/uploads/$uuid";
  $uploadMeta = json_decode(file_get_contents($fileName . ".json"), true);
  $hc = unserialize(hex2bin($uploadMeta['hc']));

  $fhIn = fopen('php://input', 'r');
  $fhOut = fopen($fileName . ".bin", 'a');
  while (!feof($fhIn)) {
    $data = fread($fhIn, 8192*2);
    hash_update($hc, $data);
    fwrite($fhOut, $data);
  }
  fclose($fhOut);
  fclose($fhIn);

  $uploadMeta["hc"] = bin2hex(serialize($hc));
  file_put_contents($fileName . ".json", json_encode($uploadMeta));

  header("Location: /v2/$name/blobs/uploads/$uuid");
  header("Range: 0-" . filesize($fileName . ".bin"));
  header("Content-Length: 0");
  header("Content-Type: ");
  header("Docker-Upload-UUID: $uuid");
  http_response_code(202);
}

function completeUpload($name, $uuid, $digest) {
  $documentRoot = getenv('DOCUMENT_ROOT');
  $fileName = "$documentRoot/v2/blobs/uploads/$uuid";
  $uploadMeta = json_decode(file_get_contents($fileName . ".json"), true);
  $hc = unserialize(hex2bin($uploadMeta['hc']));
  $hv = hash_final($hc);

  $fileNameNew = "$documentRoot/v2/blobs/sha256/$hv";
  rename($fileName . ".bin", $fileNameNew);
  unlink($fileName . ".json");

  header("Location: /v2/$name/blobs/sha256:$hv");
  header("Range: 0-" . filesize($fileNameNew));
  header("Content-Length: 0");
  header("Docker-Content-Digest: $hv");
  http_response_code(201);
}

parse_str(getenv("QUERY_STRING"), $qp);
$name = $qp['name'];
$uuid = $qp['uuid'];
$digest = $qp['digest'];

$documentRoot = getenv('DOCUMENT_ROOT');
$requestMethod = getenv('REQUEST_METHOD');

if ($requestMethod === 'POST' && empty($uuid)) {
  startUpload($name);
} else if ($requestMethod === 'PATCH' && !empty($uuid)) {
  patchUpload($name, $uuid);
} else if ($requestMethod === 'PUT' && !empty($uuid) && !empty($digest)) {
  completeUpload($name, $uuid, $digest);
}

?>
