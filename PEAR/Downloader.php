<?php
//
// +----------------------------------------------------------------------+
// | PHP Version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 1997-2003 The PHP Group                                |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available through the world-wide-web at the following url:           |
// | http://www.php.net/license/3_0.txt.                                  |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Stig Bakken <ssb@php.net>                                   |
// |          Tomas V.V.Cox <cox@idecnet.com>                             |
// |          Martin Jansen <mj@php.net>                                  |
// +----------------------------------------------------------------------+
//
// $Id$

require_once 'PEAR/Common.php';
require_once 'PEAR/Registry.php';
require_once 'PEAR/Dependency.php';
require_once 'PEAR/Remote.php';
require_once 'System.php';

/**
 * Administration class used to download PEAR packages and maintain the
 * installed package database.
 *
 * @since PEAR 1.4
 * @author Greg Beaver <cellog@php.net>
 */
class PEAR_Downloader extends PEAR_Common
{
    /**
     * @var PEAR_Config
     * @access private
     */
    var $_config;

    /**
     * @var PEAR_Registry
     * @access private
     */
    var $_registry;

    /**
     * @var PEAR_Remote
     * @access private
     */
    var $_remote;

    /**
     * Preferred Installation State (snapshot, devel, alpha, beta, stable)
     * @var string|null
     * @access private
     */
    var $_preferredState;

    /**
     * Options from command-line passed to Install.
     * 
     * Recognized options:<br />
     *  - onlyreqdeps   : install all required dependencies as well
     *  - alldeps       : install all dependencies, including optional
     *  - installroot   : base relative path to install files in
     *  - force         : force a download even if warnings would prevent it
     * @see PEAR_Command_Install
     * @access private
     * @var array
     */
    var $_options;

    /**
     * Downloaded Packages after a call to download().
     * 
     * Format of each entry:
     *
     * <code>
     * array('pkg' => 'package_name', 'file' => '/path/to/local/file',
     *    'info' => array() // parsed package.xml
     * );
     * </code>
     * @access private
     * @var array
     */
    var $_downloadedPackages = array();

    /**
     * Packages slated for download.
     * 
     * This is used to prevent downloading a package more than once should it be a dependency
     * for two packages to be installed.
     * Format of each entry:
     *
     * <pre>
     * array('package_name1' => parsed package.xml, 'package_name2' => parsed package.xml,
     * );
     * </pre>
     * @access private
     * @var array
     */
    var $_toDownload = array();
    
    /**
     * Array of every package installed, with names lower-cased.
     * 
     * Format:
     * <code>
     * array('package1' => 0, 'package2' => 1, );
     * </code>
     * @var array
     */
    var $_installed = array();
    
    /**
     * @var array
     * @access private
     */
    var $_errorStack = array();

    // {{{ PEAR_Downloader()
    
    function PEAR_Downloader(&$ui, $options, &$config)
    {
        $this->_options = $options;
        $this->_config = &$config;
        $this->_preferredState = $this->_config->get('preferred_state');
        if (!$this->_preferredState) {
            // don't inadvertantly use a non-set preferred_state
            $this->_preferredState = null;
        }

        $php_dir = $this->_config->get('php_dir');
        if (isset($this->_options['installroot'])) {
            if (substr($this->_options['installroot'], -1) == DIRECTORY_SEPARATOR) {
                $this->_options['installroot'] = substr($this->_options['installroot'], 0, -1);
            }
            $php_dir = $this->_prependPath($php_dir, $this->_options['installroot']);
        }
        $this->_registry = &new PEAR_Registry($php_dir);
        $this->_remote = &new PEAR_Remote($config);

        if (isset($this->_options['alldeps']) || isset($this->_options['onlyreqdeps'])) {
            $this->_installed = $this->_registry->listPackages();
            array_walk($this->_installed, create_function('&$v,$k','$v = strtolower($v);'));
            $this->_installed = array_flip($this->_installed);
        }
        parent::PEAR_Common($ui);
    }
    
    // }}}
    // {{{ _downloadFile()
    /**
     * @param string filename to download
     * @param string version/state
     * @param string original value passed to command-line
     * @param string|null preferred state (snapshot/devel/alpha/beta/stable)
     *                    Defaults to configuration preferred state
     * @return null|PEAR_Error|string
     * @access private
     */
    function _downloadFile($pkgfile, $version, $origpkgfile, $state = null)
    {
        if (is_null($state)) {
            $state = $this->_preferredState;
        }
        // {{{ check the package filename, and whether it's already installed
        $need_download = false;
        if (preg_match('#^(http|ftp)://#', $pkgfile)) {
            $need_download = true;
        } elseif (!@is_file($pkgfile)) {
            if ($this->validPackageName($pkgfile)) {
                if ($this->_registry->packageExists($pkgfile)) {
                    if (empty($this->_options['upgrade']) && empty($this->_options['force'])) {
                        $errors[] = "$pkgfile already installed";
                        return;
                    }
                }
                $pkgfile = $this->getPackageDownloadUrl($pkgfile, $version);
                $need_download = true;
            } else {
                if (strlen($pkgfile)) {
                    $errors[] = "Could not open the package file: $pkgfile";
                } else {
                    $errors[] = "No package file given";
                }
                return;
            }
        }
        // }}}

        // {{{ Download package -----------------------------------------------
        if ($need_download) {
            $downloaddir = $this->_config->get('download_dir');
            if (empty($downloaddir)) {
                if (PEAR::isError($downloaddir = System::mktemp('-d'))) {
                    return $downloaddir;
                }
                $this->log(3, '+ tmp dir created at ' . $downloaddir);
            }
            $callback = $this->ui ? array(&$this, '_downloadCallback') : null;
            $this->pushErrorHandling(PEAR_ERROR_RETURN);
            $file = $this->downloadHttp($pkgfile, $this->ui, $downloaddir, $callback);
            $this->popErrorHandling();
            if (PEAR::isError($file)) {
                if ($this->validPackageName($origpkgfile)) {
                    if (!PEAR::isError($info = $this->_remote->call('package.info',
                          $origpkgfile))) {
                        if (!count($info['releases'])) {
                            return $this->raiseError('Package ' . $origpkgfile .
                            ' has no releases');
                        } else {
                            return $this->raiseError('No releases of preferred state "'
                            . $state . '" exist for package ' . $origpkgfile .
                            '.  Use ' . $origpkgfile . '-state to install another' .
                            ' state (like ' . $origpkgfile .'-beta)',
                            PEAR_INSTALLER_ERROR_NO_PREF_STATE);
                        }
                    } else {
                        return $pkgfile;
                    }
                } else {
                    return $this->raiseError($file);
                }
            }
            $pkgfile = $file;
        }
        // }}}
        return $pkgfile;
    }
    // }}}
    // {{{ getPackageDownloadUrl()

    function getPackageDownloadUrl($package, $version = null)
    {
        if ($version) {
            $package .= "-$version";
        }
        if ($this === null || $this->_config === null) {
            $package = "http://pear.php.net/get/$package";
        } else {
            $package = "http://" . $this->_config->get('master_server') .
                "/get/$package";
        }
        if (!extension_loaded("zlib")) {
            $package .= '?uncompress=yes';
        }
        return $package;
    }

    // }}}
    // {{{ extractDownloadFileName($pkgfile, &$version)

    function extractDownloadFileName($pkgfile, &$version)
    {
        if (@is_file($pkgfile)) {
            return $pkgfile;
        }
        // regex defined in Common.php
        if (preg_match(PEAR_COMMON_PACKAGE_DOWNLOAD_PREG, $pkgfile, $m)) {
            $version = (isset($m[3])) ? $m[3] : null;
            return $m[1];
        }
        $version = null;
        return $pkgfile;
    }

    // }}}

    // }}}
    // {{{ getDownloadedPackages()

    /**
     * Retrieve a list of downloaded packages after a call to {@link download()}.
     * 
     * Also resets the list of downloaded packages.
     * @return array
     */
    function getDownloadedPackages()
    {
        $ret = $this->_downloadedPackages;
        $this->_downloadedPackages = array();
        $this->_toDownload = array();
        return $ret;
    }

    // }}}
    // {{{ download()

    /**
     * Download any files and their dependencies, if necessary
     *
     * @param array a mixed list of package names, local files, or package.xml
     */
    function download($packages)
    {
        $mywillinstall = array();
        $state = $this->_preferredState;

        // {{{ download files in this list if necessary
        foreach($packages as $pkgfile) {
            if (!is_file($pkgfile)) {
                $pkgfile = $this->_downloadNonFile($pkgfile);
                if (PEAR::isError($pkgfile)) {
                    return $pkgfile;
                }
                if ($pkgfile === false) {
                    continue;
                }
            } // end is_file()
            $tempinfo = $this->infoFromAny($pkgfile);
            if (isset($this->_options['alldeps']) || isset($this->_options['onlyreqdeps'])) {
                // ignore dependencies if there are any errors
                if (!PEAR::isError($tempinfo)) {
                    $mywillinstall[strtolower($tempinfo['package'])] = @$tempinfo['release_deps'];
                }
            }
            $this->_downloadedPackages[] = array('pkg' => $tempinfo['package'],
                                       'file' => $pkgfile, 'info' => $tempinfo);
        } // end foreach($packages)
        // }}}

        // {{{ extract dependencies from downloaded files and then download
        // them if necessary
        if (isset($this->_options['alldeps']) || isset($this->_options['onlyreqdeps'])) {
            $deppackages = array();
            foreach ($mywillinstall as $package => $alldeps) {
                if (!is_array($alldeps)) {
                    // there are no dependencies
                    continue;
                }
                foreach($alldeps as $info) {
                    if ($info['type'] != 'pkg') {
                        continue;
                    }
                    $ret = $this->_processDependency($info, $mywillinstall);
                    if ($ret === false) {
                        continue;
                    }
                    if (PEAR::isError($ret)) {
                        return $ret;
                    }
                    $deppackages[] = $ret;
                } // foreach($alldeps
            }

            if (count($deppackages)) {
                // check dependencies' dependencies
                // combine the list of packages to install
                $temppack = array();
                foreach($this->_downloadedPackages as $p) {
                    $temppack[] = strtolower($p['info']['package']);
                }
                foreach($deppackages as $pack) {
                    $temppack[] = strtolower($pack);
                }
                $this->_toDownload = array_merge($this->_toDownload, $temppack);
                $this->download($deppackages);
            }
        } // }}} if --alldeps or --onlyreqdeps
    }

    // }}}
    // {{{ _downloadNonFile($pkgfile)
    
    /**
     * @return false|PEAR_Error|string false if loop should be broken out of,
     *                                 string if the file was downloaded,
     *                                 PEAR_Error on exception
     * @access private
     */
    function _downloadNonFile($pkgfile)
    {
        $origpkgfile = $pkgfile;
        $state = null;
        $pkgfile = $this->extractDownloadFileName($pkgfile, $version);
        if (preg_match('#^(http|ftp)://#', $pkgfile)) {
            $pkgfile = $this->_downloadFile($pkgfile, $version, $origpkgfile);
            if (PEAR::isError($pkgfile)) {
                return $pkgfile;
            }
            $tempinfo = $this->infoFromAny($pkgfile);
            if (isset($this->_options['alldeps']) || isset($this->_options['onlyreqdeps'])) {
                // ignore dependencies if there are any errors
                if (!PEAR::isError($tempinfo)) {
                    $mywillinstall[strtolower($tempinfo['package'])] = @$tempinfo['release_deps'];
                }
            }
            $this->_downloadedPackages[] = array('pkg' => $tempinfo['package'],
                                       'file' => $pkgfile, 'info' => $tempinfo);
            return false;
        }
        if (!$this->validPackageName($pkgfile)) {
            return $this->raiseError("Package name '$pkgfile' not valid");
        }
        // ignore packages that are installed unless we are upgrading
        $curinfo = $this->_registry->packageInfo($pkgfile);
        if ($this->_registry->packageExists($pkgfile)
              && empty($this->_options['upgrade']) && empty($this->_options['force'])) {
            $this->log(0, "Package '{$curinfo['package']}' already installed, skipping");
            return false;
        }
        $curver = $curinfo['version'];
        $releases = $this->_remote->call('package.info', $pkgfile, 'releases');
        if (!count($releases)) {
            return $this->raiseError("No releases found for package '$pkgfile'");
        }
        // Want a specific version/state
        if ($version !== null) {
            // Passed Foo-1.2
            if ($this->validPackageVersion($version)) {
                if (!isset($releases[$version])) {
                    return $this->raiseError("No release with version '$version' found for '$pkgfile'");
                }
            // Passed Foo-alpha
            } elseif (in_array($version, $this->getReleaseStates())) {
                $state = $version;
                $version = 0;
                foreach ($releases as $ver => $inf) {
                    if ($inf['state'] == $state && version_compare("$version", "$ver") < 0) {
                        $version = $ver;
                    }
                }
                if ($version == 0) {
                    return $this->raiseError("No release with state '$state' found for '$pkgfile'");
                }
            // invalid postfix passed
            } else {
                return $this->raiseError("Invalid postfix '-$version', be sure to pass a valid PEAR ".
                                         "version number or release state");
            }
        // Guess what to download
        } else {
            $states = $this->betterStates($this->_preferredState, true);
            $possible = false;
            $version = 0;
            foreach ($releases as $ver => $inf) {
                if (in_array($inf['state'], $states) && version_compare("$version", "$ver") < 0) {
                    $version = $ver;
                }
            }
            if ($version == 0 && !isset($this->_options['force'])) {
                return $this->raiseError('No release with state equal to: \'' . implode(', ', $states) .
                                         "' found for '$pkgfile'");
            } elseif ($version == 0) {
                $this->log(0, "Warning: $pkgfile is state '$inf[state]' which is less stable " .
                              "than state '$state'");
            }
        }
        // Check if we haven't already the version
        if (empty($this->_options['force'])) {
            if ($curinfo['version'] == $version) {
                $this->log(0, "Package '{$curinfo['package']}-{$curinfo['version']}' already installed, skipping");
                return false;
            } elseif (version_compare("$version", "{$curinfo['version']}") < 0) {
                $this->log(0, "Already got '{$curinfo['package']}-{$curinfo['version']}' greater than requested '$version', skipping");
                return false;
            }
        }
        return $this->_downloadFile($pkgfile, $version, $origpkgfile, $state);
    }
    
    // }}}
    // {{{ _processDependency($info)
    
    /**
     * Process a dependency, download if necessary
     * @param array dependency information from PEAR_Remote call
     * @param array packages that will be installed in this iteration
     * @return false|string|PEAR_Error
     * @access private
     */
    function _processDependency($info, $mywillinstall)
    {
        $state = $this->_preferredState;
        if (!isset($this->_options['alldeps']) && isset($info['optional']) &&
              $info['optional'] == 'yes') {
            // skip optional deps
            $this->log(0, "skipping Package $package optional dependency $info[name]");
            return false;
        }
        // {{{ get releases
        $releases = $this->_remote->call('package.info', $info['name'], 'releases');
        if (PEAR::isError($releases)) {
            return $releases;
        }
        if (!count($releases)) {
            if (!isset($this->_installed[strtolower($info['name'])])) {
                $this->pushError("Package $package dependency $info[name] ".
                            "has no releases");
            }
            return false;
        }
        $found = false;
        $save = $releases;
        while(count($releases) && !$found) {
            if (!empty($state) && $state != 'any') {
                list($release_version, $release) = each($releases);
                if ($state != $release['state'] &&
                    !in_array($release['state'], $this->betterStates($state)))
                {
                    // drop this release - it ain't stable enough
                    array_shift($releases);
                } else {
                    $found = true;
                }
            } else {
                $found = true;
            }
        }
        if (!count($releases) && !$found) {
            $get = array();
            foreach($save as $release) {
                $get = array_merge($get,
                    $this->betterStates($release['state'], true));
            }
            $savestate = array_shift($get);
            $this->pushError( "Release for $package dependency $info[name] " .
                "has state '$savestate', requires $state");
            return false;
        }
        if (in_array(strtolower($info['name']), $this->_toDownload) ||
              isset($mywillinstall[strtolower($info['name'])])) {
            // skip upgrade check for packages we will install
            return false;
        }
        if (!isset($this->_installed[strtolower($info['name'])])) {
            // check to see if we can install the specific version required
            if ($info['rel'] == 'eq') {
                return $info['name'] . '-' . $info['version'];
            }
            // skip upgrade check for packages we don't have installed
            return $info['name'];
        }
        // }}}

        // {{{ see if a dependency must be upgraded
        $inst_version = $this->_registry->packageInfo($info['name'], 'version');
        if (!isset($info['version'])) {
            // this is a rel='has' dependency, check against latest
            if (version_compare($release_version, $inst_version, 'le')) {
                return false;
            } else {
                return $info['name'];
            }
        }
        if (version_compare($info['version'], $inst_version, 'le')) {
            // installed version is up-to-date
            return false;
        }
        return $info['name'];
    }
    
    // }}}
    // {{{ pushError($errmsg, $code)
    
    /**
     * @param string
     * @param integer
     */
    function pushError($errmsg, $code = -1)
    {
        array_push($this->_errorStack, array($errmsg, $code));
    }
    
    // }}}
    // {{{ getErrorMsgs()
    
    function getErrorMsgs()
    {
        $msgs = array();
        $errs = $this->_errorStack;
        foreach ($errs as $err) {
            $msgs[] = $err[0];
        }
        $this->_errorStack = array();
        return $msgs;
    }
    
    // }}}
}
// }}}

?>
