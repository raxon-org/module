<?php
/**
 * @author          Remco van der Velde
 * @since           2026-01-28
 * @copyright       (c) Remco van der Velde
 * @license         MIT
 * @version         1.0
 */
namespace Module;

use Exception_ol;

use Exception_ol\ErrorException;
use Exception_ol\DirectoryCreateException;
use Exception_ol\FileMoveException;

class Dir {
    const CHMOD = 0750;
    const TYPE = 'Dir';
    const SEPARATOR = DIRECTORY_SEPARATOR;
    const FORMAT_FLAT = 'flat';

    private null|object $node = null;

    private int $count = 0;

    /**
     * @throws DirectoryCreateException
     */
    public static function change(string $dir): string
    {
        $tmp = getcwd() . DIRECTORY_SEPARATOR;
        if(is_dir($dir) === false){
            Dir::create($dir, Dir::CHMOD);
            File::chown($dir, 'www-data', 'www-data');
        }
        chdir($dir);
        return $tmp;
    }

    public static function current(): string
    {
        $cwd =  getcwd();
        if(substr($cwd, -1) !== Dir::SEPARATOR){
            $cwd .= Dir::SEPARATOR;
        }
        return $cwd;
    }

    /**
     * @throws DirectoryCreateException
     */
    public static function create(string $url, int|null $chmod=null): bool
    {
        if($url !== Dir::SEPARATOR){
            $url = rtrim($url, Dir::SEPARATOR);
        }
        if(File::exists($url) && !Dir::is($url)){
            unlink($url);
        }
        if(File::exists($url) && Dir::is($url)){
            return true;
        } else {
            try {
                $mkdir = false;
                if(!File::exists($url)){
                    if($chmod === null){
                        $mkdir = @mkdir($url, Dir::CHMOD, true);
                    } else {
                        $mkdir = @mkdir($url, $chmod, true);
                    }
                }
                return $mkdir;
            }
            catch (Exception | ErrorException $exception){
                throw new DirectoryCreateException('Cannot create directory: ' . $url, 0, $exception);
            }
        }
    }

    public static function exists(string $url): bool
    {
        if($url !== Dir::SEPARATOR){
            $url = rtrim($url, Dir::SEPARATOR);
        }
        if(
            File::exists($url) === true &&
            Dir::is($url) === true
        ){
            return true;
        }
        return false;
    }
    public static function is(string $url): bool
    {
        if($url !== Dir::SEPARATOR){
            $url = rtrim($url, Dir::SEPARATOR);
        }
        return is_dir($url);
    }

    public static function size(string $url, bool $recursive=false): bool | int
    {
        if(!Dir::is($url)){
            return false;
        }
        $url = rtrim($url, Dir::SEPARATOR);
        $dir = new Dir();
        $read = $dir->read($url, $recursive, Dir::FORMAT_FLAT);
        $total = 0;
        foreach($read as $file){
            $size = filesize($file->url);
            $total += $size;
        }
        return $total;
    }

    public static function name(string $url, int|null $levels=null): string
    {
        $is_backslash = false;
        if(stristr($url, '\\') !== false){
            $url = str_replace('\\', '/', $url);
            $is_backslash = true;
        }
        if(is_null($levels)){
            $name = dirname($url);
        } else {
            $levels += 0;
            $name = dirname($url, (int) $levels);
        }
        if($name == '.'){
            return '';
        }
        elseif(substr($name, -1, 1) != '/'){
            $name .= '/';
        }
        if($is_backslash === true){
            $name = str_replace('/', '\\', $name);
        }
        return $name;
    }

    public function ignore(string|array|null $ignore=null, string|array|null $attribute=null): mixed
    {
        $node = $this->node();
        if(!isset($node)){
            $node = (object) [];
        }
        if(!isset($node->ignore)){
            $node->ignore = [];
        }
        if($ignore !== null){
            if(is_array($ignore) && $attribute === null){
                $node->ignore = $ignore;
            }
            elseif($ignore == 'delete' && $attribute === null){
                $node->ignore = [];
            }
            elseif($ignore=='list' && $attribute !== null){
                $node->ignore = $attribute;
            }
            elseif($ignore=='find'){
                if(substr($attribute,-1) !== Dir::SEPARATOR){
                    $attribute .= Dir::SEPARATOR;
                }
                foreach ($node->ignore as $item){
                    if(stristr($attribute, $item) !== false){
                        return true;
                    }
                }
                return false;
            } else {
                if(substr($ignore,-1) !== Dir::SEPARATOR){
                    $ignore .= Dir::SEPARATOR;
                }
                $node->ignore[] = $ignore;
            }
        }
        $node = $this->node($node);
        return $node->ignore;
    }

    public function read(string $url='', bool $recursive=false, string $format='flat', $disable_symlink=false): bool | array
    {
        if(substr($url,-1) !== Dir::SEPARATOR){
            $url .= Dir::SEPARATOR;
        }
        if($this->ignore('find', $url)){
            return [];
        }
        $count = $this->count();
        $list = [];
        $cwd = getcwd();
        if(is_dir($url) === false){
            return false;
        }
        try {
            @chdir($url);
        } catch (Exception | ErrorException $exception){
            return false;
        }
        try {
            $list_symlink = [];
            if ($handle = @opendir($url)) {
                while (false !== ($entry = readdir($handle))) {
                    $recursiveList = [];
                    if($entry == '.' || $entry == '..'){
                        continue;
                    }
                    $file = (object) [];
                    $file->url = $url . $entry;
                    if(is_dir($file->url)){
                        $file->url .= Dir::SEPARATOR;
                        $file->type = Dir::TYPE;
                    }
                    if($this->ignore('find', $file->url)){
                        continue;
                    }
                    $file->name = $entry;
                    if(isset($file->type)){
                        if(!empty($recursive)){
                            $directory = new Dir();
                            $directory->ignore('list', $this->ignore());
                            $recursiveList = $directory->read($file->url, $recursive, $format);
                            $count =  $count + $directory->count();
                            if($format !== 'flat'){
                                $file->list = $recursiveList;
                                unset($recursiveList);
                            }
                        }
                    } else {
                        $file->type = File::TYPE;
                    }
                    if(is_link($entry)){
                        if($disable_symlink === true){
                            continue;
                        }
                        // array of symlinks, if one is in there it should break the current directory
                        if(!in_array($entry, $list_symlink, true)){
                            $list_symlink[] = $entry;
                        } else {
                            if(is_resource($handle)){
                                closedir($handle);
                            }
                            if(is_dir($cwd)){
                                @chdir($cwd);
                            }
                            $this->count($count);
                            return $list;
                        }
                        $file->link = true;
                    }
                    $list[] = $file;
                    if(!empty($recursiveList)){
                        foreach ($recursiveList as $recursive_file){
                            $list[] = $recursive_file;
                        }
                    }
                    $count++;
                }
            }
        } catch (Exception | ErrorException $exception){
            return false;
        }
        if(is_resource($handle)){
            closedir($handle);
        }
        if(is_dir($cwd)){
            @chdir($cwd);
        }
        $this->count($count);
        return $list;
    }

    public function count(int|null $count=null): int
    {
        if($count === null){
            return $this->getCount();
        } else {
            $this->setCount($count);
            return $this->getCount();
        }
    }

    public function setCount(int $count=0): void
    {
        $this->count = $count;
    }

    public function getCount(): int
    {
        return $this->count ?? 0;
    }

    /**
     * @throws DirectoryCreateException
     */
    public static function amount (string $url) : int
    {
        $dir = Dir::current();
        Dir::change($url);
        exec('ls -1p | grep -v / | wc -l', $output);
        if(isset($output[0])){
            $output = (int) $output[0];
        } else {
            $output = 0;
        }
        Dir::change($dir);
        return $output;
    }

    public static function copy(string $source, string $target): bool
    {
        if(substr($source, -1) !== Dir::SEPARATOR){
            $source .= Dir::SEPARATOR;
        }
        if(is_dir($source)){
            if(!is_dir($target)){
                Dir::create($target, Dir::CHMOD);
            }
            $dir = new Dir();
            $read = $dir->read($source, true);
            foreach($read as $file){
                if($file->type === File::TYPE){
                    $dir_name = Dir::name($file->url);
                    $temp = explode($source, $dir_name);
                    if(array_key_exists(1, $temp)){
                        $destination_dir = $target . $temp[1];
                        if(!is_dir($destination_dir)){
                            Dir::create($destination_dir, Dir::CHMOD);
                        }
                        $destination = $destination_dir . $file->name;
                        File::copy($file->url, $destination);
                    }
                }
            }
            return true;
        } else {
            return false;
        }
    }

    public static function rename(string $source, string $destination, bool $overwrite=false): bool
    {
        try {
            return File::rename($source, $destination, $overwrite);
        } catch (Exception | FileMoveException $exception){
            return false;
        }
    }

    public static function move(string $source, string $destination, bool $overwrite=false): bool
    {
        try {
            return File::move($source, $destination, $overwrite);
        } catch (Exception | FileMoveException $exception){
            return false;
        }
    }

    public static function remove(string $dir): bool
    {
        if(Dir::is($dir) === false){
            return true;
        }
        if($dir === '/'){
            return false;
        }
        $dir = escapeshellarg($dir);
        exec('rm -rf ' . $dir);
        return true;
    }

    public function delete(string $dir): bool
    {
        if(Dir::is($dir) === false){
            return true;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $nr => $file) {
            if($this->ignore('find', "$dir/$file")){
                continue;
            }
            if(is_dir("$dir/$file")){
                $this->delete("$dir/$file");
            } else {
                unlink("$dir/$file");
                unset($files[$nr]);
            }
        }
        if($this->ignore('find', "$dir")){
            return true;
        }
        return rmdir($dir);
    }
    public function node(mixed $node=null): mixed
    {
        if($node !== null){
            $this->setNode($node);
        }
        return $this->getNode();
    }
    private function setNode(mixed $node=null): void
    {
        $this->node = $node;
    }
    private function getNode(): mixed
    {
        return $this->node;
    }

    public static function ucfirst(string $dir): string
    {
        $explode = explode('/', $dir);
        $result = '';
        foreach($explode as $part){
            if(empty($part)){
                continue;
            }
            $result .= ucfirst($part) . '/';
        }
        return $result;
    }
}