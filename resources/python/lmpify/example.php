<?php
var_dump(
  json_decode(
    shell_exec('/var/www/python/lmptfy/calculate_print.sh --f "path_to_stl_file.stl" --lh 0.08 --ps 100 --lw 0.22 --mc 22.0')
  )
);

