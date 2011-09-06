<?php
/**
 * Run Polit.
 */
function polit() {
    _p_extension_load('common');
    
    // read options
    $args = $_SERVER['argv'];
    array_shift($args);
    $options = _p_read_options($args);
    $options += array (
        'base_dir' => '.',
    );
    
    // store the base dir
    _p_base_dir($options['base_dir']);
    
    // usage help
    if (isset ($options['h']) || isset ($options['help'])) {
        _p_usage();
        return;
    }
    
    // set log level
    if (isset ($options['v'])) {
        _p_log_level(-1);
    } else if (isset ($options['q'])) {
        _p_log_level(1);
    } else if (isset ($options['qq'])) {
        _p_log_level(2);
    }
    
    if (isset ($options['group'])) {
        p_select_groups(array_map('trim', array_filter(explode(',', $options['group']))));
    }
    if (isset ($options['host'])) {
        p_select_hosts(array_map('trim', array_filter(explode(',', $options['host']))));
    }
    
    if (empty ($options['cmd'])) {
        p_error("No command specified");
        return;
    }
    $f = "polit_{$options['cmd']}";
    if (!is_callable($f)) {
        $f = "p_common_{$options['cmd']}";
        if (!is_callable($f)) {
            p_error("Command {$options['cmd']} does not exist");
            return;
        }
    }
    call_user_func_array($f, $options['cmd_args']);
}

/**
 * Log a message to STDOUT.
 * @param string $msg
 */
function p_log($msg) {
    if (_p_log_level() <= 0) {
        fwrite(STDOUT, _p_log_format('INFO', $msg));
    }
}

/**
 * Output a raw message to STDOUT.
 * @param string $msg
 */
function p_output($msg) {
    if (_p_log_level() <= 0) {
        fwrite(STDOUT, $msg);
    }
}

/**
 * Log an error to STDOUT.
 * @param string $err
 */
function p_error($err) {
    if (_p_log_level() <= 1) {
        fwrite(STDERR, _p_log_format('ERROR', $err, 'red'));
    }
}

/**
 * Indent the log output.
 */
function p_log_indent($msg = null) {
    if (_p_log_level() <= -1) {
        if ($msg) {
            fwrite(STDOUT, _p_log_format('', $msg, 'dark_gray'));
        }
        _p_log_prefix("> ");
    }
}

/**
 * Unindent the log output.
 */
function p_log_unindent() {
    if (_p_log_level() <= -1) {
        _p_log_prefix('', "> ");
    }
}

/**
 * Make an absolute path, based on the local base_dir.
 * @param string $path
 * @return string
 */
function p_local_path($path) {
    return _p_path_merge(_p_base_dir(), $path);
}

/**
 * Run a shell command.
 * @param string $cmd
 * @param array $options
 */
function p_shell_cmd($cmd, array $options = array ()) {
    $options += array ('stdout' => '_p_shell_cmd_out', 'stderr' => '_p_shell_cmd_err');
    $descriptorspec = array (
        0 => array ('pipe', 'r'),
        1 => array ('pipe', 'w'),
        2 => array ('pipe', 'w'),
    );
    $restart = false;
    do {
        if ($restart) {
            p_log("Restarted.");
        }
        $restart = false;
        $pp = proc_open($cmd, $descriptorspec, $pipes);
        stream_set_blocking($pipes[1], 0);
        stream_set_blocking($pipes[2], 0);
        $skips = 0;
        while (false !== ($num = stream_select($r = array ($pipes[1], $pipes[2]), $w = null, $e = null, 2, 0))) {
            if ($num > 0) {
                $skips = 0;
                if ($line = fgets($pipes[1], 2048)) {
                    call_user_func($options['stdout'], $line);
                }
                if ($line = fgets($pipes[2], 2048)) {
                    call_user_func($options['stderr'], $line);
                }
            } else {
                $skips++;
                if ($skips % 10 == 0) { // ask every 20 seconds
                    fwrite(STDERR, _p_log_color("It's taking a while. Restart?", 'red') . "\n> ");
                    $n = stream_select($r = array (STDIN), $w = null, $e = null, 5, 0);
                    if ($n == 0) {
                        fwrite(STDERR, "\nGuess not!\n");
                    } else {
                        $ln = fgets(STDIN, 4);
                        if (trim($ln) == 'y') {
                            // start again
                            $restart = true;
                            p_log("Forcing restart...");
                            
                            // kill the process and all sub processes
                            $status = proc_get_status($pp);
                            posix_setpgid($status['pid'], $status['pid']);
                            posix_kill(-$status['pid'], 9);
                            proc_terminate($pp);
                            break;
                        }
                    }
                }
                fwrite(STDERR, '.');
            }
            if ((!$pipes[1] || feof($pipes[1])) && (!$pipes[2] || feof($pipes[2]))) {
                break; // done here
            }
        }
        array_map('fclose', $pipes);
        proc_close($pp);
    } while ($restart);
}

function _p_shell_cmd_out($line) {
    p_log("> " . trim($line));
}

function _p_shell_cmd_err($line) {
    p_error("> " . trim($line));
}

function p_log_server_id(array $server) {
    $name = '';
    if (!empty ($server['name'])) {
        $name = $server['name'];
    } else if (!empty ($server['host'])) {
        $name = $server['host'];
    }
    $length = 30;
    return substr(str_pad($name, $length, " "), 0, $length);
}

/**
 * Read options from the args array.
 * @param array &$args  argv array. Options will be stripped from this array.
 * @return array $options
 */
function _p_read_options(array &$args) {
    $options = array ();
    foreach ($args as $k => $arg) {
        if (substr($arg, 0, 2) == '--') {
            $arg = substr($arg, 2);
            if (strpos($arg, '=')) {
                list ($param, $value) = explode('=', $arg);
                $options[$param] = $value;
            } else {
                $options[$arg] = true;
            }
            unset ($args[$k]);
        } else if (substr($arg, 0, 1) == '-') {
            $chars = str_split(substr($arg, 1));
            $options += array_combine($chars, array_fill(0, sizeof($chars), true));
            unset ($args[$k]);
        } else {
            $a = array_values($args);
            $options['cmd'] = array_shift($a);
            $options['cmd_args'] = $a;
            break;
        }
    }
    return $options;
}

function _p_path_merge($base, $path) {
    if (substr($path, 0, 1) == '/') {
        return $path;
    } else {
        $path = rtrim($base, '/') . '/' . $path;
        if ($realpath = realpath($path)) {
            return $realpath;
        }
        return $path;
    }
}

function _p_base_dir($dir = null) {
    static $base_dir = null;
    if ($dir) {
        $base_dir = $dir;
    }
    return $base_dir;
}

function _p_extension_load($extension) {
    $dir = dirname(__FILE__);
    require_once ("{$dir}/{$extension}.php");    
}

function _p_log_color($str, $color) {
    static $colors = array (
        'red' => '0;31',
    );
    if (isset ($colors[$color])) {
        $str = "\033[{$colors[$color]}m{$str}\033[0m";
    }
    return $str;
}

function _p_log_format($label, $msg, $color = null) {
    $str = str_pad("[{$label}]", 8, " ", STR_PAD_RIGHT);
    $str .= '[' . date('Y-m-d H:i:s') . '] ';
    $str .= _p_log_prefix();
    $str .= $msg;
    if ($color) {
        $str = _p_log_color($str, $color);
    }
    $str .= "\n";
    return $str;
}

function _p_log_prefix($push = '', $pop = '') {
    static $prefix = '';
    if ($pop) {
        if (substr($prefix, -1 * strlen($pop)) == $pop) {
            $prefix = substr($prefix, 0, -1 * strlen($pop));
        }
    }
    if ($push) {
        $prefix .= $push;
    }
    return $prefix;
}

function _p_log_level($set = null) {
    static $level = 0;
    if ($set !== null) {
        $level = (int)$set;
    }
    return $level;
}

function _p_usage() {
    fputs(STDOUT, file_get_contents(dirname(__FILE__) . '/usage.txt'));
}

function p_select_groups($set = null, $reset = false) {
    static $groups = null;
    if ($set !== null || $reset) {
        $groups = $set;
    }
    return $groups;
}

function p_subselect_groups($set = null, $reset = false) {
    static $groups = null;
    if ($set !== null || $reset) {
        $groups = $set;
    }
    return $groups;
}

function p_subselect_groups_reset() {
    p_subselect_groups(null, true);
}

function p_select_hosts($set = null, $reset = false) {
    static $hosts = null;
    if ($set !== null || $reset) {
        $hosts = $set;
    }
    return $hosts;
}

function p_apply_vars($cmd, array $vars) {
    $replace = array ();
    foreach ($vars as $k => $v) {
        if (!is_array($v) && !is_object($v)) {
            $replace[$k] = $v;
        }
    }
    foreach ($replace as $from => $to) {
        $cmd = str_replace('#{' . $from . '}', $to, $cmd);
    }
    return $cmd;
}

function p_servers() {
    $groups = p_select_groups();
    $groups_sub = p_subselect_groups();
    $hosts = p_select_hosts();
    $output = array ();
    foreach (p_all_servers() as $server) {
        if ($groups !== null && !array_intersect($server['group'], $groups)) {
            continue;
        }
        if ($groups_sub !== null && !array_intersect($server['group'], $groups_sub)) {
            continue;
        }
        if ($hosts !== null && !in_array($server['host'], $hosts)) {
            continue;
        }
        $output[] = $server;
    }
    return $output;
}

function p_all_servers() {
    static $config = null;
    if ($config === null) {
        $config = array ();
        $c = _p_config_raw();
        $groups = isset ($c['groups']) ? $c['groups'] : array ();
        $servers = isset ($c['servers']) ? $c['servers'] : array ();
        foreach ($servers as $server) {
            $s_groups = isset ($server['group']) ? (is_array($server['group']) ? $server['group'] : array ($server['group'])) : array ();
            $server['group'] = $s_groups;
            $group_data = array ();
            foreach ($s_groups as $group) {
                if (isset ($groups[$group])) {
                    $group_data = array_merge($group_data, $groups[$group]);
                }
            }
            $server += $group_data;
            $config[] = $server;
        }
    }
    return $config;
}

function _p_config_raw() {
    static $config = null;
    if ($config === null) {
        $config = array ();
        if (file_exists($path = p_local_path("config/servers.yml"))) {
            $data = file_get_contents($path);
            $yaml = yaml_parse($data);
            if (is_array($yaml)) {
                $config = $yaml;
            }
        }
    }
    return $config;
}
