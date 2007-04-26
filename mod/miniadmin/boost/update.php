<?php
  /**
   * @author Matthew McNaney <mcnaney at gmail dot com>
   * @version $Id$
   */

function miniadmin_update(&$content, $version)
{
    switch ($version) {
    case version_compare($version, '0.0.5', '<'):
        $content[] = 'Fixed XHTML incompatibilities.';

    case version_compare($version, '1.0.0', '<'):
        $content[] = '- Changed to allow the addition of multiple yet single link submissions.';

    case version_compare($version, '1.0.1', '<'):
        $content[] = '<pre>
1.0.1 changes
------------------
+ Added translate function</pre>';

    case version_compare($version, '1.1.0', '<'):
        $content[] = '<pre>';
        $files = array('conf/config.php', 'templates/mini_admin.tpl', 'templates/alt_mini_admin.tpl');

        if (PHPWS_Boost::updateFiles($files, 'miniadmin')) {
            $content[] = '--- Successfully updated the following files:';
        } else {
            $content[] = '--- Was unable to copy the following files:';
        }
        $content[] = '     ' . implode("\n     ", $files);

        $content[] = '
1.1.0 changes
------------------
+ Added ability to pick different miniadmin template
+ Updated language functions.
</pre>';

    }
    return true;
}

?>
