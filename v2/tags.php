<?php

parse_str(getenv("QUERY_STRING"), $qp);
$name = $qp['name'];

$documentRoot = getenv('DOCUMENT_ROOT');

$fileName = "$documentRoot/v2/manifests/tag2digest.json";
$tag2Digest = json_decode(file_get_contents($fileName), true);

$tags = $tag2Digest[$name];
$respTags = array();
foreach ($tags as $tag => $value) {
    $respTags[] = $tag;
}
$respObject = array( "name" => $name, "tags" => $respTags);

$content = json_encode($respObject);

header("Content-Type: application/json");
header("Content-Length: " . strlen($content));
echo $content;
?>
