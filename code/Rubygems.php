<?php

/**
 * @package compass
 */
class Rubygems extends Object {
	
	/** 
	 * @var bool - is ruby available? 
	 */
	private static $ruby_ok = null;

	/** 
	 * @var bool - is the version of rubygems currently available good enough? 
	 */
	private static $gem_version_ok = null;

    /**
     * @var bool - are we on Windows?
     */
    protected static $is_win = null;
	
	/**
	 * Get the path that gems live in, creating it if it doesn't exist .
	 *
	 * @return string
	 */
	private static function gem_path() {
		$path = TEMP_FOLDER . '/gems';
		if (defined('SS_GEM_PATH')) $path = SS_GEM_PATH;
		
		if (!file_exists($path)) mkdir($path, 0770);
		return $path;	
	}

    /**
     * @return bool True if we are on windows.
     */
    protected static function is_win()
    {
        if (self::$is_win !== null)
            return self::$is_win;

        self::$is_win = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        return self::$is_win;
    }

	/**
	 * Internal helper function that calls an external executable - can't just 
	 * use backticks, as we want stderr and stdout as separate variables
	 * 
	 * Also sets this modules gem path into the environment of the external 
	 * executable
	 *
	 * @param $cmd string - the command to run
	 * @param $stdout reference to string - the resultant stdout
	 * @param $stderr reference to string - the resultant stderr
	 *
	 * @return int - process exit code, or -1 if the process couldn't be executed
	 */
	protected static function _run($cmd, &$stdout, &$stderr) {

        if (self::is_win())
        {
            $stdout = exec($cmd, $output, $status);
            return $status;
        }

		$descriptorspec = array(
			0 => array("pipe", "r"), // stdin is a pipe that the child will read from
			1 => array("pipe", "w"), // stdout is a pipe that the child will write to
			2 => array("pipe", "w")  // stderr is a file to write to
		);
		 
		$gempath = self::gem_path();
		$process = proc_open("HOME='$gempath' GEM_HOME='$gempath' " . (@$_GET['flush'] ? "FLUSH={$_GET['flush']} " : '') . $cmd, $descriptorspec, $pipes);
		
		$stdout = "";
		$stderr = "";
		 
		if (!is_resource($process)) return -1;

		fclose($pipes[0]); // close child's input immediately
		stream_set_blocking($pipes[1],false);
		stream_set_blocking($pipes[2],false);
		 
		while (true) {
			$read = array();
			$w = null;
			$e = null;
			
			if (!feof($pipes[1])) $read[]= $pipes[1];
			if (!feof($pipes[2])) $read[]= $pipes[2];
			 
			if (!$read) break;
			if (!stream_select($read, $w, $e, 120)) break;
			 
			foreach ($read as $r) {
				$s = fread($r,1024);
				if ($r == $pipes[1]) $stdout .= $s; else $stderr .= $s;
			}
		}
		 
		fclose($pipes[1]);
		fclose($pipes[2]);
		 
		return proc_close($process);
	}
	
	/**
	 * Make sure a gem is available
	 *
	 * @param $gem string - the name of the gem to install
	 * @param $version string - the specific version to install
	 * @param $tryupdating bool - if the gem is present, check for update? (hits the internet, so slow)
	 *
	 * @return null | string - an error string on error, nothing on success
	 */
	public static function require_gem($gem, $version = null, $tryupdating = false) {
		// Check that ruby exists
		if (self::$ruby_ok === null) {
            if (self::is_win()) {
                self::_run('ruby -v', $output, $err);

                // If a command doesn't exist, exec returns an empty str.
                self::$ruby_ok = (bool)$output;
            }
            else
			    self::$ruby_ok = (bool)`which ruby`;
		}
		
		if (!self::$ruby_ok) {
			return 'Ruby isn\'t present. The "ruby" command needs to be in the webserver\'s path';
		}
		
		// Check that rubygems exists and is a good enough version
		if (self::$gem_version_ok === null) {
			$code = self::_run('gem environment version', $ver, $err);
			
			if ($code !== 0) {
				return 'Ruby is present, but there was a problem accessing the \
					current rubygems version - is rubygems available? The "gem" \
					command needs to be in the webserver\'s path.';
			}
			
			$vers = explode('.', $ver);
			
			self::$gem_version_ok = ($vers[0] >= 1 && $vers[1] >= 2);
		}

		if (!self::$gem_version_ok) {
			return "Rubygems is too old. You have version $ver, but we need at \
				least version 1.2. Please upgrade.";
		}
		
		$veropt = $version ? sprintf('-v "%s"', $version) : '';

		// See if the gem exists. If not, try adding it
		self::_run("gem list $gem -i $veropt", $out, $err);

		if (trim($out) != 'true' || $tryupdating) {
			$code = self::_run("gem install $gem $veropt --no-rdoc --no-ri", $out, $err);
			
			if ($code !== 0) {
				return "Could not install required gem $gem. Either manually \
					install, or repair error. Error message was: $err";
			}
		}

        return null;
	}

    public static function run_cmd($command, $args="", &$out, &$err) {
        return self::_run(
            $command . ' ' . $args,
            $out,
            $err
        );
    }
	
	/**
	 * Execute a command provided by a gem
	 *
	 * @param string | array $gem - the name of the gem, or an array of names 
	 * of gems, possibly associated with versions, to require.
	 *
	 * @param string $command - the name of the command
	 * @param string $args - arguments to pass to the command
	 * @param string $out - stdout result of the command
	 * @param string $err - stderr result of the command
	 *
	 * @return int - process exit code, or -1 if the process couldn't be executed
	 */
	public static function run($gems, $command, $args="", &$out, &$err) {
		$reqs = array();

		if (is_string($gems)) {
			$reqs[] = sprintf('-e "gem \"%s\", \">= 0\""', $gems);
		} else {
			foreach ($gems as $gem => $version) {
				if (!$version) { 
					$version = '>= 0'; 
				}
				
				$reqs[] = sprintf('-e "gem \"%s\", \"%s\""', $gem, $version);
			}
		}
		
		$version = (isset($gems[$command])) && !empty($gems[$command]) ? $gems[$command] : ">= 0";
		$reqs = implode(' ', $reqs);

		return self::_run(
			sprintf('ruby -rubygems %s -e "load Gem.bin_path(\"%s\", \"%s\", \"%s\")" -- %s',
				$reqs, $command, $command, $version, $args
			), 
			$out, 
			$err
		);
	}
}
