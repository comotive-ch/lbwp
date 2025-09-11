<!DOCTYPE html>
<html lang="de-DE">
<head></head>
<body class="browscap-test">
<?php
foreach (get_browser() as $key => $value) {
  echo '<div id="' . $key . '"><span class="name">' . $key . '</span>: <span class="value">' . strtolower($value) . '</span></div>' . PHP_EOL;
}
?>
</body>
</html>