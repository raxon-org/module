<?php
/**
 * @author          Remco van der Velde
 * @since           2026-01-28
 * @copyright       (c) Remco van der Velde
 * @license         MIT
 * @version         1.0
 */
namespace Module;

use Exception;
use Exception\ErrorException;
use Exception\FileAppendException;
use Exception\FileMoveException;
use Exception\FileWriteException;

class File {
    const CHMOD = 0640;
    const TYPE = 'File';
    const SCHEME_HTTP = 'http';
    const USER_WWW = 'www-data';
    const STRING = 'string';
    const ARRAY = 'array';
    const SIZE = 'size';
    const BYTE = 'byte';
    const BYTES = 'bytes';
    const LINE = 'line';
    const LINES = 'lines';

    const IN = 'In ';
    const ELAPSED = 'Elapsed: ';

    public static function is(string $url=''): bool
    {
        $url = rtrim($url, '/');
        return is_file($url);
    }

    public static function is_link(string $url=''): bool
    {
        $url = rtrim($url, '/');
        return is_link($url);
    }

    public static function is_readable(string $url=''): bool
    {
        $url = rtrim($url, '/');
        return is_readable($url);
    }

    public static function is_writeable(string $url=''): bool
    {
        $url = rtrim($url, '/');
        return is_writeable($url);
    }

    public static function is_resource(string $url=''): bool
    {
        return is_resource($url);
    }

    public static function is_upload(string $url=''): bool
    {
        $url = rtrim($url, '/');
        return is_uploaded_file($url);
    }

    public static function dir(string $directory=''): string
    {
        return str_replace('\\\/', '/', rtrim($directory,'\\\/')) . '/';
    }

    public static function owner(string $url=''): string
    {
        if(File::is_link($url)){
            return '';
        }
        $owner = fileowner($url);
        $user_info = posix_getpwuid($owner);
        return $user_info['name'] ?? '';
    }

    public static function group(string $url=''): string
    {
        if(File::is_link($url)){
            return '';
        }
        $owner = filegroup($url);
        $group_info = posix_getgrgid($owner);
        return $group_info['name'] ?? '';
    }

    public static function rights(string $url=''): string
    {
        if(File::is_link($url)){
            return '0600';
        }
        return substr(sprintf('%o', fileperms($url)), -4);
    }

    public static function mtime(string $url=''): bool | int | null
    {
        try {
            return @filemtime($url); //added @ async deletes & reads can cause triggers otherways
        } catch(Exception $exception){
            return null;
        }

    }

    public static function atime(string $url=''): bool | int | null
    {
        try {
            return @fileatime($url); //added @ async deletes & reads can cause triggers otherways
        } catch (Exception $exception){
            return null;
        }
    }

    public static function namespace(string $url): ?string
    {
        $code = File::read($url);
        $tokens = token_get_all($code);
        $namespace = '';
        $captureNamespace = false;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $captureNamespace = true;
                    continue;
                }
                if ($captureNamespace && ($token[0] === T_STRING || $token[0] === T_NS_SEPARATOR)) {
                    $namespace .= $token[1];
                } elseif ($captureNamespace && $token[0] === T_WHITESPACE) {
                    continue;
                } elseif($captureNamespace) {
                    break;
                }
            }
        }
        return $namespace ?: null;
    }

    public static function link(string $source, string $destination): bool
    {
        if(substr($source, -1, 1) === '/'){
            $source = substr($source, 0, -1);
        }
        if(substr($destination, -1, 1) === '/'){
            $destination = substr($destination, 0, -1);
        }
        $source = escapeshellarg($source);
        $destination = escapeshellarg($destination);
        system('ln -s ' . $source . ' ' . $destination);
        return true;
    }

    public static function readlink(string $url, bool $final=false): string
    {
        $url = escapeshellarg($url);
        if($final){
            $output = system('readlink -f ' . $url);
        } else {
            $output = system('readlink ' . $url);
        }
        return $output;
    }

    public static function count(string $directory='', bool $include_directory=false): int
    {
        $dir = new Dir();
        $read = $dir->read($directory);
        if(!empty($include_directory)){
            return count($read);
        } else {
            $count = 0;
            foreach($read as $file){
                if(!property_exists($file, 'type')){
                    continue;
                }
                if($file->type == File::TYPE){
                    $count++;
                }
            }
            return $count;
        }
    }

    public static function exist(string $url): bool
    {
        if(!is_string($url)){
            return false;
        }
        elseif($url == '/'){
            return file_exists($url);
        } else {
            $url = rtrim($url, '/');
            return file_exists($url);
        }
    }

    public static function touch(string $url, int|null $time=null, int|null $atime=null): bool
    {
        if($time === null){
            $time = time();
        }
        if($atime === null){
            try {
                return @touch($url, $time); //wsdl1 not working
            } catch (Exception $exception){
                return false;
            }
        } else {
            try {
                return @touch($url, $time, $atime);
            } catch (Exception $exception){
                return false;
            }
        }
    }

    public static function chown(string $url='', string|null $owner=null, string|null $group=null, bool $recursive=false): bool
    {
        if($owner === null){
            $owner = 'root:root';
        }
        if($group == null){
            $explode = explode(':', $owner, 2);
            if(count($explode) == 1){
                $group = $owner;
            } else {
                $owner = $explode[0];
                $group = $explode[1];
            }
        }
        $output = [];
        $owner = escapeshellarg($owner);
        $group = escapeshellarg($group);
        $url = escapeshellarg($url);
        if(posix_geteuid() !== 0){
            trace();
            return false;
        }
        if($recursive){
            exec('chown ' . $owner . ':' . $group . ' -R ' . $url, $output);
        } else {
            exec('chown ' . $owner . ':' . $group . ' ' . $url, $output);
        }
        return true;
    }

    /**
     * @throws FileMoveException
     */
    public static function move(string $source='', string $destination='', bool $overwrite=false): bool
    {
        if(substr($source, -1, 1) === DIRECTORY_SEPARATOR){
            $source = substr($source, 0, -1);
        }
        if(substr($destination, -1, 1) === DIRECTORY_SEPARATOR){
            $destination = substr($destination, 0, -1);
        }
        if(
            $overwrite &&
            File::exist($destination)
        ){
            if(File::is_link($destination)){
                File::remove($destination);
            }
            elseif(Dir::is($destination)){
//              continue overwrite
            } else {
                File::remove($destination);
            }
            $source = escapeshellarg($source);
            $destination = escapeshellarg($destination);
            exec('mv ' . $source . ' ' . $destination);
            return true;
        } elseif(
            !$overwrite &&
            File::exist($destination)
        ){
            throw new FileMoveException('Destination file already exists...');
        } else {
            $source = escapeshellarg($source);
            $destination = escapeshellarg($destination);
            exec('mv ' . $source . ' ' . $destination);
            return true;
        }
    }

    /**
     * @throws FileMoveException
     */
    public static function rename(string $source='', string $destination='', bool $overwrite=false): bool
    {
        if(substr($source, -1, 1) === DIRECTORY_SEPARATOR){
            $source = substr($source, 0, -1);
        }
        if(substr($destination, -1, 1) === DIRECTORY_SEPARATOR){
            $destination = substr($destination, 0, -1);
        }
        $exist = File::exist($source);
        if($exist === false){
            throw new FileMoveException('Source file doesn\'t exist...');
        }
        $exist = File::exist($destination);
        if(
            $overwrite === false &&
            File::exist($destination)
        ){
            throw new FileMoveException('Destination file already exists...');
        }
        if(Dir::is($source)){
            if(
                $exist &&
                $overwrite === false
            ){
                throw new FileMoveException('Destination directory exists...');
            }
            elseif($exist){
                if(Dir::is($destination)){
                    throw new FileMoveException('Destination directory exists and needs to be deleted first...');
                } else {
                    try {
                        File::delete($destination);
                        return @rename($source, $destination);
                    } catch (Exception  | ErrorException $exception){
                        return false;
                    }
                }
            } elseif($overwrite === false){
                try {
                    return @rename($source, $destination);
                } catch (Exception | ErrorException $exception){
                    return false;
                }
            }
        }
        elseif(File::is($source)){
            try {
                return @rename($source, $destination);
            } catch (Exception | ErrorException $exception){
                return false;
            }
        }
        return false;
    }

    public static function chmod(string $url, int $mode=0640): bool
    {
        return chmod($url, $mode);
    }


    public static function put(string $url, string $data='', array $options=[]): bool | int
    {
        $return = $options['return'] ?? 'size';
        $flags = $options['flags'] ?? LOCK_EX;
        $size = file_put_contents($url, $data, $flags);
        switch($return){
            case File::LINE:
            case File::LINES:
                $explode = explode(PHP_EOL, $data);
                return $size !== false ? count($explode) : false;
            case File::SIZE:
            case File::BYTE:
            case File::BYTES:
            default:
                return $size;
        }
    }

    /**
     * @throws FileWriteException
     * @bug The original "write" had a bug, while "put" did not have that bug and they did the same function.
     */
    public static function write(string $url, string $data='', $options=[]): bool | int
    {
        return File::put($url, $data, $options);
    }

    /**
     * @throws FileAppendException
     */
    public static function append(string $url='', string $data=''): bool|int
    {
        $url = (string) $url;
        $data = (string) $data;
        $resource = @fopen($url, 'a');
        if($resource === false){
            return $resource;
        }        
        flock($resource, LOCK_EX);
        for ($written = 0; $written < strlen($data); $written += $fwrite) {
            $fwrite = fwrite($resource, substr($data, $written));
            if ($fwrite === false) {
                break;
            }
        }
        flock($resource, LOCK_UN);
        fclose($resource);
        if($written != strlen($data)){
            throw new FileAppendException('File.append failed, written != strlen data....');
        } else {
            return $written;
        }
    }

    public static function read(string $url='', array $options=[]) : string | array
    {        
        $return = $options['return'] ?? File::STRING;
        if(strpos($url, File::SCHEME_HTTP) === 0){
            //check network connection first (@) added for that              //error
            try {
                $file = @file($url);
                switch($return){
                    case File::ARRAY:
                        if(empty($file)){
                            return [];
                        }
                        return $file;
                    default:
                        if(empty($file)){
                            return '';
                        }
                        return implode('', $file);
                }

            } catch (Exception $exception){
                echo $exception . PHP_EOL;
                switch($return){
                    case File::ARRAY:
                        return [];
                    default:
                        return '';
                }
            }
        }
        if(empty($url)){
            switch($return){
                case File::ARRAY:
                    return [];
                default:
                    return '';
            }
        }
        try {
            switch($return){
                case File::ARRAY:
                    return file($url);
                default:
                    return file_get_contents($url);
            }

        } catch (Exception $exception){
            echo $exception . PHP_EOL;
            switch($return){
                case File::ARRAY:
                    return [];
                default:
                    return '';
            }
        }
    }

    public static function tail(string $url, int $n=1, bool $is_array=false) : string | array
    {
        if(File::exist($url)){
            if($n < 1){
                $n = 1;
            }
            $n = (string) $n;
            $command = 'tail -n '. escapeshellarg($n) .' ' . escapeshellarg($url);
            exec($command, $output);
            $output = implode(PHP_EOL, $output);
            $output = explode("\r", $output);
            $output = implode(PHP_EOL, $output);
            $output = explode(PHP_EOL, $output);
            $reverse = [];
            for($i = 0; $i < $n; $i++){
                $reverse[] = array_pop($output);
            }
            $output = array_reverse($reverse);
            if($is_array){
                return $output;
            } else {
                return implode(PHP_EOL, $output);
            }
        }
        return '';
    }

    /**
     * @throws Exception
     */
    public static function copy(string $source, string $destination): bool
    {
        try {
            return copy($source, $destination);
        }
        catch(\ErrorException $exception){
            throw new Exception ('Couldn\'t copy source (' . $source . ') to destination (' . $destination .').');
        }
    }


    public static function remove(string $url): bool
    {
        $url = escapeshellarg($url);
        exec('rm  ' . $url);
        return true;
    }

    public static function delete(string $url): bool
    {
        try {
            $url = rtrim($url, '/');
            return @unlink($url); //added @ async deletes & reads can cause triggers other ways
        } catch (Exception $exception){
            return false;
        }

    }

    public static function extension(string|null $url=null): string
    {
        if(substr($url, -1) === '/'){
            return '';
        }
        $url = basename($url);
        $ext = explode('.', $url);
        if(!isset($ext[1])){
            $extension = '';
        } else {
            $extension = array_pop($ext);
        }
        return $extension;
    }

    public static function basename(string $url, string $extension=''): string
    {
        if(strstr($url, '\\') !== false){
            $url = str_replace('\\', '/', $url);
        }
        $filename = basename($url);
        $explode = explode('?', $filename, 2);
        $filename = $explode[0];
        $filename = str_replace(
            [
                ':',
                '='
            ],
            [
                '.',
                '-'
            ],
            $filename
        );
        if(!str_starts_with($extension, '.')){
            $extension = '.' . $extension;
        }
        return basename($filename, $extension);
    }

    public static function extension_remove(string $filename, array $extension=[]): string
    {
        if(!is_array($extension)){
            $extension = [($extension)];
        }
        foreach($extension as $ext){
            $ext = '.' . ltrim($ext, '.');
            $filename = explode($ext, $filename, 2);
            if(count($filename) > 1 && empty(end($filename))){
                array_pop($filename);
            }
            $filename = implode($ext, $filename);
        }
        return $filename;
    }

    public static function ucfirst(string $url): string
    {
        $explode = explode('.', $url);
        $extension = null;
        if(array_key_exists(1, $explode)){
            $extension = array_pop($explode);
            $result = '';
            foreach($explode as $part){
                if(empty($part)){
                    continue;
                }
                $result .= ucfirst($part) . '.';
            }
        } else {
            $result = $explode[0];
        }
        if($extension){
            $result .= $extension;
        }
        return $result;
    }

    public static function size(string $url): int
    {
        try {
            return @filesize($url); //pagefile error
        } catch(Exception $exception){
            return 0;
        }
    }

    public static function size_calculation(int|float|string $calculation=''): float|int
    {
        $b = str_contains(strtolower($calculation), 'b');
        $k = str_contains(strtolower($calculation), 'k');
        $m = str_contains(strtolower($calculation), 'm');
        $g = str_contains(strtolower($calculation), 'g');
        $t = str_contains(strtolower($calculation), 't');
        $p = str_contains(strtolower($calculation), 'p');
        $e = str_contains(strtolower($calculation), 'e');
        $number = false;
        if (preg_match('/[0-9]+(?:\.[0-9]+)?/', $calculation, $matches)) {
            $number = (float) $matches[0];
        }
        if($number === false){
            return 0;
        }
        $number = round($number, 2);
        if($k){
            $number = $number * 1024;
        }
        elseif($m){
            $number = $number * 1024 * 1024;
        }
        elseif($g){
            $number = $number * 1024 * 1024 * 1024;
        }
        elseif($t){
            $number = $number * 1024 * 1024 * 1024 * 1024;
        }
        elseif($p){
            $number = $number * 1024 * 1024 * 1024 * 1024 * 1024;
        }
        elseif($e){
            $number = $number * 1024 * 1024 * 1024 * 1024 * 1024 * 1024;
        }
        return $number;
    }

    /**
     * @throws Exception
     */
    public static function upload(Data $upload, string $target): bool
    {
        return move_uploaded_file($upload->data('tmp_name'), $target . $upload->data('name'));
    }

    public static function size_format(float|int $size=0): string
    {
        if($size < 1024){
            return '0 B';
        }
        elseif($size < 1024 * 1024){
            return round($size / 1024, 2) . ' KB';
        }
        elseif($size < 1024 * 1024 * 1024){
            return round($size / 1024 / 1024, 2) . ' MB';
        }
        elseif($size < 1024 * 1024 * 1024 * 1024){
            return round($size / 1024 / 1024 / 1024, 2) . ' GB';
        }
        elseif($size < 1024 * 1024 * 1024 * 1024 * 1024){
            return round($size / 1024 / 1024 / 1024 / 1024, 2) . ' TB';
        }
        elseif($size < 1024 * 1024 * 1024 * 1024 * 1024 * 1024){
            return round($size / 1024 / 1024 / 1024 / 1024 / 1024, 2) . ' PB';
        } else {
            return round($size / 1024 / 1024 / 1024 / 1024 / 1024 / 1024, 2) . ' EB';
        }

    }

    public static function time_format(int $seconds=0, string $string=File::IN): string
    {
        $days = floor($seconds / (3600 * 24));
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds / 60) % 60);
        $seconds = $seconds % 60;
        if($days > 0){
            if($days === 1){
                $string .= $days . ' day and ';
            } else {
                $string .= $days . ' days and ';
            }

        }
        if($hours > 0){
            if($hours === 1){
                $string .= $hours . ' hour and ';
            } else {
                $string .= $hours . ' hours and ';
            }

        }
        if ($minutes > 0){
            if($minutes === 1){
                $string .= $minutes . ' minute and ';
            } else {
                $string .= $minutes . ' minutes and ';
            }

        }
        if($seconds < 0){
            $string = 'Almost there';
        } else {
            if($seconds === 1){
                $string .= $seconds . ' second';
            } else {
                $string .= $seconds . ' seconds';
            }
        }
        return $string;
    }
}