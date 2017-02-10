<?php

class Mirror {
	static private $ALREADY_PARSED = array();
	
	static public function test_key($login, $passwd) {
		$mirrors = Config::get('mirror');
		shuffle($mirrors);
		foreach($mirrors as $mirror) {
			$tries = 0;
			while(++$tries <= Config::get('default_errors_quantity')) {
				if ($tries > 1)  {
					usleep(CONNECTTIMEOUT*1000000);
				}
				if (Config::get('update_version_ess') == 1) {
					$header = @get_headers("http://$login:$passwd@$mirror".$GLOBALS['TESTKEY_REAL_PATH_ESS']);
				} else {
					$header = @get_headers("http://$login:$passwd@$mirror".$GLOBALS['TESTKEY_REAL_PATH_NOD']);
				}
				if (preg_match("/401/", $header[0])) return false;
				else return true;
			}
			return $header[0];
		}
	}
	
	static public function find_best_mirrors($key) {
		$mirrors = Config::get('mirror');
		$login = $key[0];
		$password = $key[1];
		global $DIRECTORIES;
		$dir = $DIRECTORIES[array_rand($DIRECTORIES)];
		$test_mirrors = array();
		if (function_exists('curl_multi_init')){
			$treads = 20;
			$treads = (sizeof($mirrors) < $treads) ? sizeof($mirrors) : $treads;
			$master = curl_multi_init();
			$options = array(
				CURLOPT_CONNECTTIMEOUT => CONNECTTIMEOUT,
				CURLOPT_HEADER => false,
				CURLOPT_NOBODY => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5
			);
			for ($i = 0; $i < $treads; $i++) {
				$ch = curl_init();
				$url = "http://$mirrors[$i]/$dir/update.ver";
				$options[CURLOPT_URL] = $url;
				$options[CURLOPT_USERPWD] = $login.':'.$password;
				curl_setopt_array($ch, $options);
				curl_multi_add_handle($master, $ch);
			}
			do {
				while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
				curl_multi_select($master);
				if ($execrun != CURLM_OK) break;
				while($done = curl_multi_info_read($master)) {
					$info = curl_getinfo($done['handle']);
					if ($info['http_code'] == 200) {
						$url = parse_url($info['url']);
						$test_mirrors[$url['host']] = round($info['total_time']*1000);
					}
					$i++;
					if (isset($mirrors[$i])) {
						$ch = curl_init();
						$url = "http://$mirrors[$i]/$dir/update.ver";
						$options[CURLOPT_URL] = $url;
						curl_setopt_array($ch, $options);
						curl_multi_add_handle($master, $ch);
					}
					curl_multi_remove_handle($master, $done['handle']);
					curl_close($done['handle']);
				}
			} while ($running);
			curl_multi_close($master);
		} else {
			ini_set('default_socket_timeout', CONNECTTIMEOUT);
			foreach($mirrors as $mirror) {
				$time = microtime(true);
				$header = @get_headers("http://".$login.':'.$password."@$mirror/$dir/update.ver", 1);
				if (preg_match("/200/", $header[0])) {
					$test_mirrors[$mirror] = round((microtime(true) - $time)*1000); 
				}
				unset($header);
			}
			ini_restore('default_socket_timeout');
		}
		asort($test_mirrors);
		$best_mirrors = array();
		foreach ($test_mirrors as $mirror => $time) $best_mirrors[] = $mirror;
		$GLOBALS['mirrors'] = $best_mirrors;
	}
	
	static public function check_mirror($version, $key) {
		$login = $key[0];
		$password = $key[1];
		global $DIRECTORIES;
		$dir = $DIRECTORIES[$version];
		$cur_update_ver = Tools::ds(Config::get('web_dir'), $dir, 'update.ver');
		$tmp_path = Tools::ds(Config::get('web_dir'), TMP_PATH, $dir);
		$old_version = Mirror::get_DB_version($cur_update_ver);
		@mkdir($tmp_path, 0755, true);
		$arch = Tools::ds($tmp_path, 'update.rar');
		$unarch = Tools::ds($tmp_path, 'update.ver');
		while (!empty($GLOBALS['mirrors'])){
			$mirror_array_values = array_values($GLOBALS['mirrors']);
			$mirror = array_shift($mirror_array_values);
			$header = Tools::download_file("http://".$login.':'.$password."@$mirror/$dir/update.ver", $arch);
			if (is_array($header) and !empty($header[0]) and preg_match("/200/", $header[0])){
				if (preg_match("/text/", $header['Content-Type'])){
					rename($arch, $unarch);
				} else {
					tools::extract_file($arch, $tmp_path);
					@unlink($arch);
				}
				$new_version = mirror::get_DB_version($unarch);
				$content = @file_get_contents($unarch);
				if ((intval($new_version) >= intval($old_version)) and preg_match('/'.Config::get('update_version_filter').'/', $content)) break;
				else @unlink($unarch);
			}
			array_shift($GLOBALS['mirrors']);
		}
		if (!empty($GLOBALS['mirrors'])) return array($mirror, $new_version);
		else return array(null, null);
	}
	
	static public function get_DB_version($file) {
		if (!file_exists($file)) return null;
		$content = file_get_contents($file);
		$upd = parser::parse_line($content, "version");
		$max = 0;
		if (isset($upd)) {
			foreach($upd as $key) {
				$tmp = explode(' ', $key);
				$max = $max < intval($tmp[0]) ? $key : $max;
			}
		}
		return $max;
	}

	static public function download_signature($version, $mirror, $pair_key, $version = null) {
		global $DIRECTORIES;
		$dir = Config::get('web_dir');
		$cur_update_ver = Tools::ds($dir, $DIRECTORIES[$version], 'update.ver');
		$tmp_update_ver = Tools::ds($dir, TMP_PATH, $DIRECTORIES[$version], 'update.ver');
		$content = @file_get_contents($tmp_update_ver);
		preg_match_all('#\[\w+\][^\[]+#', $content, $matches);
		if (!empty($matches)){
			$new_content = '';
			$new_files = array();
			$total_size = 0;

			// Parse files from .ver file
			foreach ($matches[0] as $container) {
				parse_str((str_replace("\r\n", "&", $container)), $output);
				if (intval($version) != 10) {
    				if ( empty($output['file']) or empty($output['size']) or empty($output['date']) or
	    				(!empty($output['language']) and !in_array($output['language'], Config::get('update_version_lang'))) or
		    			(Config::get('update_version_x32') != 1 and preg_match("/32|86/", $output['platform'])) or
			    		(Config::get('update_version_x64') != 1 and preg_match("/64/", $output['platform'])) or
				    	(Config::get('update_version_ess') != 1 and preg_match("/ess/", $output['type'])) ){
					    continue;
				    }
				} else {
				    if ( empty($output['file']) or empty($output['size']) or
                    	(Config::get('update_version_x32') != 1 and preg_match("/32|86/", $output['platform'])) or
                    	(Config::get('update_version_x64') != 1 and preg_match("/64/", $output['platform']))){
                    	continue;
				    }
				}
				$new_files[] = array($output['file'], $output['size']);
				$total_size += $output['size'];
				$new_content .= $container;
			}
			
			// Create hardlinks/copy file for empty needed files (name, size)
			$old_files = array();
			$Directory = new RecursiveDirectoryIterator($dir);
			$It        = new RecursiveIteratorIterator($Directory, RecursiveIteratorIterator::SELF_FIRST);
			$Regex1    = new RecursiveRegexIterator($Directory,'/v\d+-'.Config::get('update_version_filter').'/i');
			$It2       = new RecursiveIteratorIterator($Regex1); 
			$Regex2    = new RegexIterator($It2,'/\.nup$/i');
			foreach($Regex2 as $file) $old_files[] = $file->getPathname();

			$download_files = array();
			$needed_files = array();
			foreach ($new_files as $array) {
				list($file, $size) = $array;
				$dirfile = Tools::ds($dir, $file);
				$needed_files[] = $dirfile;
				if (file_exists($dirfile) and (@filesize($dirfile) != $size)) unlink($dirfile);
				if (!file_exists($dirfile)) {
					$results = preg_grep('/'.basename($file).'$/', $old_files);
					$test = false;
					if (!empty($results)){
						foreach ($results as $result){
							if ($size == @filesize($result)) $test = true; break;
						}
					}
					if ($test){
						$res = dirname($dirfile);
						if (!file_exists($res)) mkdir($res, 0755, true);
						if (Config::get('create_hard_links')){
							if (Config::get('create_hard_links') == 'link') {
								link($result, $dirfile);
							} elseif (Config::get('create_hard_links') == 'fsutil') {
								shell_exec(sprintf("fsutil hardlink create %s %s", $dirfile, $result));
							}
							Log::write_log(Language::t("Created hard link for %s", basename($file)), 3, $version);
						} else {
							copy($result, $dirfile);
							Log::write_log(Language::t("Copied file %s", basename($file)), 3, $version);
						}
					} else {
						$download_files[] = $file;
					}
				}
			}

			// Download files
			$total_downloads = 0;
			$average_speed = 0;
			$start_time = microtime(true);
			if (!empty($download_files)){
				shuffle($download_files);
				Log::write_log( Language::t("Downloading %d files", count($download_files)), 3, $version );
				if (Tools::ping($mirror) != true ) list($mirror, $new_version) = Mirror::check_mirror($version,$pair_key);
				if ($mirror != null) {
					if (function_exists('curl_multi_init')){
						$test = false;
						$treads = 10;
						$file = array();
						$treads = (count($download_files) < $treads) ? count($download_files) : $treads;
						$master = curl_multi_init();
						$options = array(
							CURLOPT_USERPWD => "$pair_key[0]:$pair_key[1]",
							CURLOPT_BINARYTRANSFER  => true,
							CURLOPT_CONNECTTIMEOUT => CONNECTTIMEOUT,
							CURLOPT_HEADER => false,
							CURLOPT_FOLLOWLOCATION => true,
							CURLOPT_MAXREDIRS => 5
						);
						$handles = array();
						for ($i = 0; $i < $treads; $i++) {
							$ch = curl_init();
							$handles[$ch] = $mirror;
							$res = dirname(Tools::ds($dir, $download_files[$i]));
							if (!@file_exists($res)) @mkdir($res, 0755, true);
							$url = "http://".$mirror.$download_files[$i];
							$file[$url] = fopen(Tools::ds($dir, $download_files[$i]), 'w');
							$options[CURLOPT_URL] = $url;
							$options[CURLOPT_FILE] = $file[$url];
							curl_setopt_array($ch, $options);
							curl_multi_add_handle($master, $ch);
						}
						do {
							while(($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM);
							curl_multi_select($master);
							if ($execrun != CURLM_OK) break;
							while($done = curl_multi_info_read($master)) {
								$ch = $done['handle'];
								$info = curl_getinfo($ch);
								$host = $handles[$ch];
								if ($info['http_code'] == 200)  {
									@fclose($file[$info['url']]);
									unset($file[$info['url']]);
									$parsed_url = parse_url($info['url']);
									Log::write_log(Language::t("From %s downloaded %s [%s] [%s/s]", $host, basename($info['url']), Tools::bytesToSize1024($info['download_content_length']), Tools::bytesToSize1024($info['speed_download'])), 3, $version);
									unset($handles[$ch]);
									$total_downloads += $info['download_content_length'];
									$i++;
									if (isset($download_files[$i])) {
										$ch = curl_init();
										$res = dirname(Tools::ds($dir, $download_files[$i]));
										if (!@file_exists($res)) @mkdir($res, 0755, true);
										$url = "http://".$mirror.$download_files[$i];
										$handles[$ch] = $mirror;
										$file[$url] = @fopen(Tools::ds($dir, $download_files[$i]), 'w');
										$options[CURLOPT_URL] = $url;
										$options[CURLOPT_FILE] = $file[$url];
										curl_setopt_array($ch, $options);
										curl_multi_add_handle($master, $ch);
									}
								} else {
									@fclose($file[$info['url']]);
									Log::write_log( Language::t("Error download url %s", $info['url']), 3, $version );
									print_r ($info);
									$parsed_url = parse_url($info['url']);
									if (!empty($GLOBALS['mirrors'])){
										if ($host == array_shift(array_values($GLOBALS['mirrors']))) {
											list($mirror, $new_version) = Mirror::check_mirror($version);
										}
										if ($mirror != null){
											$ch = curl_init();
											$url = "http://".$mirror.$parsed_url['path'];
											$handles[$ch] = $mirror;
											$file[$url] = @fopen(Tools::ds($dir, $parsed_url['path']), 'w');
											$options[CURLOPT_URL] = $url;
											$options[CURLOPT_FILE] = $file[$url];
											curl_setopt_array($ch, $options);
											curl_multi_add_handle($master, $ch);
											
										} else $test = true;
									} else $test = true;
								}
								curl_multi_remove_handle($master, $done['handle']);
								curl_close($done['handle']);
							}
						} while ($running);
						curl_multi_close($master);
						if ($test == true) {
							Log::write_log( Language::t("All mirrors is down!"), 3, $version );
							return array(null, null, null);
						}
					} else {
						foreach ($download_files as $file) {
							if ($mirror != null){
								$dest = Tools::ds($dir, $file);
								$test = true;
								while ($test) {
									$time = microtime(true);
									$header = Tools::download_file("http://$pair_key[0]:$pair_key[1]@$mirror$file", $dest);
									if (is_array($header) and !empty($header[0]) and preg_match("/200/", $header[0])) {
										$test = false;
										$size = $header['Content-Length'];
										$parsed_url = parse_url($mirror);
										$total_downloads += $size;
										Log::write_log(Language::t("From %s downloaded %s [%s] [%s/s]", $host, basename($file), Tools::bytesToSize1024($header['Content-Length']), Tools::bytesToSize1024($header['Content-Length']/(microtime(true) - $time))), 3, $version);
									    $total_downloads += $header['Content-Length'];
									}
									else list($mirror, $new_version) = Mirror::check_mirror($version);
									if ($mirror == null) $test = false;
								}
							} else {
								Log::write_log( Language::t("All mirrors is down!"), 3, $version );
								return array(null, null, null);
							}
						}
					}
				} else {
					Log::write_log( Language::t("All mirrors is down!"), 3, $version );
					return array(null, null, null);
				}
			}
			$end_time = microtime(true);

			// Delete not needed files
			$del_files = 0;
			foreach(glob(Tools::ds($dir, 'v'.$version.'-rel-*'), GLOB_ONLYDIR) as $list){
				foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($list), RecursiveIteratorIterator::SELF_FIRST) as $fileObject) {
					if (!$fileObject->isDir()) {
						$test_file = $fileObject->getPathname();
						if (!in_array($test_file, $needed_files)) {
							@unlink($test_file);
							$del_files++;
						}
					}
				}
			}
			if ($del_files > 0 ){
				Log::write_log( Language::t("Deleted files: %s", $del_files), 3, $version );
			}
			
			// Delete empty folders
			$del_folders = 0;
			foreach(glob(Tools::ds($dir, 'v'.$version.'-rel-*'), GLOB_ONLYDIR) as $list){
				foreach(new RecursiveDirectoryIterator($list) as $fileObject) {
					$test_folder = $fileObject->getPathname();
					if (count(glob(Tools::ds($test_folder, '*'))) === 0 ) {
						@rmdir($test_folder);
						$del_folders++;
					}
				}
				if (count(glob(Tools::ds($list, '*'))) === 0 ) {
					@rmdir($list);
					$del_folders++;
				}
			}
			if ($del_folders > 0 ){
				Log::write_log( Language::t("Deleted folders: %s", $del_folders), 3, $version );
			}
			
			Log::write_log(Language::t("Total size database: %s", Tools::bytesToSize1024($total_size)), 3, $version);
			if (count($download_files)>0) {
				$average_speed = round($total_downloads/($end_time - $start_time));
				Log::write_log(Language::t("Total downloaded: %s", Tools::bytesToSize1024($total_downloads)), 3, $version);
				Log::write_log(Language::t("Average speed: %s/s", Tools::bytesToSize1024($average_speed)), 3, $version);
			}

			$dir  = Tools::ds(Config::get('web_dir'), $DIRECTORIES[$version]);
			if (!file_exists($dir)) @mkdir($dir, 0755, true);
			@file_put_contents($cur_update_ver, $new_content);
			unlink($tmp_update_ver);
			return array($total_size, $total_downloads, $average_speed);
		} else {
			Log::write_log( Language::t("Error while parsing update.ver from %s", $mirror), 3, $version );
			unlink($tmp_update_ver);
			return array(null, null, null);
		}
	}
	
	function exp_nod($Nuser, $Npass) {
		$NodProduct = "eav";
		$NodVer = "7.0.302.8";
		$NodLang = "419";
		$SysVer = "5.1";
		$ProdCode = "6A";
		$Platform = "Windows";
	
		$hash = "";
		$Cmap = array("Z", "C", "B", "M", "K", "H", "F", "S", "Q", "E", "T", "U", "O", "X", "V", "N");
		$Cmap2 = array("Q", "A", "P", "L", "W", "S", "M", "K", "C", "D", "I", "J", "E", "F", "B", "H");
		$i = 0;
		while ($i <= 7 And $i < strlen($Npass)) {
			$a = Ord($Nuser[$i]); 
			$b = Ord($Npass[$i]);
			If ($i >= strlen($Nuser)) $a = 0;
			$f = (2 * $i) << ($b & 3);
			$h = $b ^ $a;
			$g = ($h >> 4) ^ ($f >> 4);
			$hash .= $Cmap2[$g];
			$m = ($h ^ $f) & 15;
			$hash .= $Cmap[$m];
			++$i;
		};
		$j = 0;
		While ($j <= strlen($Nuser) -1) {
			$k = ord($Nuser[$j]);
			$hash .= $Cmap[($k >> 4)];
			$hash .= $Cmap2[($k & 15)];
			++$j;
		};

		$xml = '<?xml version="1.0" encoding="utf-8"?>
		<GETLICEXP>
		<SECTION ID="1000103">
		<LICENSEREQUEST>
		<NODE NAME="UsernamePassword" VALUE="'.$hash.'" TYPE="STRING" />
		<NODE NAME="Product" VALUE="'.$NodProduct.'" TYPE="STRING" />
		<NODE NAME="Version" VALUE="'.$NodVer.'" TYPE="STRING" />
		<NODE NAME="Language" VALUE="'.$NodLang.'" TYPE="DWORD" />
		<NODE NAME="UpdateTag" VALUE="" TYPE="STRING" />
		<NODE NAME="System" VALUE="'.$SysVer.'" TYPE="STRING" />
		<NODE NAME="EvalInfo" VALUE="0" TYPE="DWORD" />
		<NODE NAME="ProductCode" VALUE="'.$ProdCode.'" TYPE="DWORD" />
		<NODE NAME="Platform" VALUE="'.$Platform.'" TYPE="STRING" />
		</LICENSEREQUEST>
		</SECTION>
		</GETLICEXP>';

		$opts = array('http' =>
			array(
				'method'  => 'POST', 
				'header'  => 'Content-type: application/x-www-form-urlencoded', 
				'content' => $xml
			)
		);
		$context  = stream_context_create($opts);
		$response = file_get_contents('http://expire.eset.com/getlicexp', false, $context);

		$LicInfo = array();

		if (function_exists('simplexml_load_string')) {
			$Rxml = simplexml_load_string($response);
				
			$node = $Rxml->SECTION->LICENSEINFO;
			foreach ($node->NODE as $child) {
				$ElemAttr = $child->attributes();
				$LicInfo[(string)$ElemAttr->NAME] = (string)$ElemAttr->VALUE;
			}
		}
		return date('d.m.y', hexdec($LicInfo['ExpirationDate']));
	}
}
?>
