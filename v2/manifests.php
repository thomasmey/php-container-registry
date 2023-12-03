<?php

function getFileTypesFromAccept($httpAcceptHeader) {
   $ah = explode(',', $httpAcceptHeader);
   return array_map('trim', $ah);
}

function error($code, $message, $detail) {
   printf('{"errors": [ {"code": "%s", "message": "%s", "detail": "%s"} ] }', $code, $message, $detail);
}

function uploadManifest($name, $reference, $contentType) {
   $documentRoot = getenv('DOCUMENT_ROOT');

   // read all content, as this is hopefully small body
   $content = file_get_contents('php://input');

   // hash content
   $contentDigest = hash('sha256', $content);

   // read lookup file contentDigest -> mediaType
   $fileName = "$documentRoot/v2/manifests/digest2type.json";
   $digest2Type = json_decode(file_get_contents($fileName), true);

   // update digest2Type with new manifest
   if(!isset($digest2Type['sha256:' . $contentDigest])) {
      $digest2Type['sha256:' . $contentDigest] = $contentType;
      file_put_contents($fileName, json_encode($digest2Type));
   }

   //TODO: check if file already exists, skip processing if so
   // write content/manifest
   $fnManifest = "$documentRoot/v2/manifests/sha256-$contentDigest";
   file_put_contents($fnManifest, $content);

   // are we called with a tag?
   if (strpos($reference, 'sha256:') === false) {
      // read lookup file tag+type -> contentDigest
      $fileName = "$documentRoot/v2/manifests/tag2digest.json";
      $tag2Digest = json_decode(file_get_contents($fileName), true);

      // name level, maybe this can be done nicer, more ididomatic...
      $nl = [];
      if(array_key_exists($name, $tag2Digest)) {
        $nl = &$tag2Digest[$name];
      } else {
        $tag2Digest[$name] = &$nl;
      }

      // tag level
      $tl = [];
      if(array_key_exists($reference, $nl)) {
        $tl = &$nl[$reference];
      } else {
        $nl[$reference] = &$tl;
      }

      if (!in_array('sha256:' . $contentDigest, $tl)) {
        $tl[] = 'sha256:' . $contentDigest;
        file_put_contents($fileName, json_encode($tag2Digest));
      }
   }

   header("Location: /v2/manifests/sha256:$contentDigest");
   header("Content-Length: 0");
   header("Docker-Content-Digest: sha256:$contentDigest");
   http_response_code(201);
}

function getManifest($name, $reference, $httpAccept) {
   $documentRoot = getenv('DOCUMENT_ROOT');

   $fileName = '';
   $contentDigest = '';

   // read lookup file contentDigest -> mediaType
   $fileName = "$documentRoot/v2/manifests/digest2type.json";
   $digest2Type = json_decode(file_get_contents($fileName), true);

   // are we called with a tag or digest?
   if (strpos($reference, 'sha256:') === 0) {
      // plain lookup
      $fileName = "$documentRoot/v2/manifests/" . str_replace('sha256:', 'sha256-', $reference);
      $contentDigest = $reference;
   } else {
      // read lookup file tag+type -> contentDigest
      $fileName = "$documentRoot/v2/manifests/tag2digest.json";
      $tag2Digest = json_decode(file_get_contents($fileName));
      $digests = $tag2Digest->$name->$reference;
   
      $fileTypes = getFileTypesFromAccept($httpAccept);
   
      foreach ($fileTypes as $fileType) {
         foreach($digests as $digest) {
            $digestType = $digest2Type[$digest];
            if($digestType === $fileType) {
              $contentDigest = $digest;
              break; // sadly no way to break outer loop
            }
         }
   
         if(!empty($contentDigest)) {
            break;
         }
      }
   
      if(empty($contentDigest)) {
        error("UNKNOWN_MANIFEST", "Missing lookup for tag", "Unknown manifest for tag $name:$reference");
        exit();
      }
   
      $fileName = "$documentRoot/v2/manifests/" . str_replace('sha256:', 'sha256-', $contentDigest);
   }
   
   $content = file_get_contents($fileName);
   if ($content === false) {
      http_response_code(404);
      error("UNKNOWN_MANIFEST", "Failed to read file content", "failed to read file from $fileName");
      exit();
   }
   
   $contentType = $digest2Type[$contentDigest];
   
   header("Docker-Content-Digest: $contentDigest");
   header("Content-Type: $contentType");
   header("Content-Length: " . strlen($content));
   echo $content;
}

parse_str(getenv("QUERY_STRING"), $qp);
$name = $qp['name'];
$reference = $qp['reference']; // tag or digest

$requestMethod = getenv('REQUEST_METHOD');

if ($requestMethod === 'GET') {
  $httpAccept = getenv('HTTP_ACCEPT');
  getManifest($name, $reference, $httpAccept);
} else if ($requestMethod === 'PUT') {
  $contentType = getenv('CONTENT_TYPE');
  uploadManifest($name, $reference, $contentType);
}
?>
