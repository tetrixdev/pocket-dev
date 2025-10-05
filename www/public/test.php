<?php
echo "User: ";
exec("id", $id);
echo implode("\n", $id) . "\n\n";
echo "Docker test:\n";
exec("docker ps 2>&1", $out, $ret);
echo "Code: $ret\n";
echo implode("\n", $out);

