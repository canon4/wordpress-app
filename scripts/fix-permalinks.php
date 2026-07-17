<?php
require_once('wp-load.php');
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);
echo "Permalinks updated to Post Name successfully!";
