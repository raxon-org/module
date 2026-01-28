<?php
/**
 * @author          Remco van der Velde
 * @since           2026-01-28
 * @copyright       (c) Remco van der Velde
 * @license         MIT
 * @version         1.0
 */
namespace Module;

use Exception_ol\DirectoryCreateException;
use Exception_ol\FileWriteException;
use Exception_ol\ObjectException;
use Exception_ol;

class Data {
    const FLAGS = 'flags';
    const OPTIONS = 'options';

    private $data;
    private $do_not_nest_key;

    private $copy;

    private $is_debug = false;

    /**
     * @throws Exception
     */
    public function __construct($data=null){
        if($data !== null){
            $this->data($data);
        }
    }

    /**
     * @param object $data
     * @param string $parameter
     * @param int $offset
     * @return NULL|boolean|string
     *@example
     *
     * cli: app test test2 test.csv
     * Data::parameter($object->data('request.input'), 'test2', -1)
     * App::parameter(App $object, 'test2', -1)
     *
     */
    public static function parameter($data, $parameter, $offset=0): mixed
    {
        $result = null;
        $value = null;
        if(
            is_string($parameter) &&
            stristr($parameter, '\\')
        ){
            //classname adjustment
            $parameter = basename(str_replace('\\', '//', $parameter));
        }
        if(
            is_numeric($parameter) &&
            is_object($data)
        ){
            if(property_exists($data, $parameter)){
                $param = $data->{$parameter};
                $result = $param;
            } else {
                $result = null;
            }
        } else {
            foreach($data as $key => $param){
                if(is_numeric($key)){
                    if(substr($param, 0, 2) === '-'){
                        continue;
                    }
                    $param = rtrim($param);
                    $tmp = explode('=', $param);
                    if(count($tmp) > 1){
                        $param = array_shift($tmp);
                        $value = implode('=', $tmp);
                    }
                    if(strtolower($param) == strtolower($parameter)){
                        if($offset !== 0){
                            if(property_exists($data, (string) ($key + $offset))){
                                if(is_scalar($data->{($key + $offset)})){
                                    $value = trim($data->{(string) ($key + $offset)});
                                } else {
                                    $value = $data->{(string) ($key + $offset)};
                                }
                            } else {
                                $result = null;
                                break;
                            }
                        }
                        if(isset($value) && $value !== null){
                            $result = $value;
                        } else {
                            $result = true;
                            return $result;
                        }
                        break;
                    }
                    $value = null;
                }
                elseif($key == $parameter){
                    if($offset < 0){
                        while($offset < 0){
                            $param = prev($data);
                            $offset++;
                        }
                        return $param;
                    }
                    elseif($offset == 0){
                        return $param;
                    } else {
                        while($offset > 0){
                            $param = next($data);
                            $offset--;
                        }
                        return $param;
                    }
                }                
            }
        }
        if($result === null || is_bool($result)){
            return $result;
        }
        if(is_scalar($result)){
            return trim($result);
        }
        return $result;
    }

    /**
     * @throws ObjectException
     * @throws Exception
     */
    public static function flags($data): object | array
    {
        $flags = (object) [];
        foreach($data as $nr => $parameter){
            if(
                is_string($parameter) &&
                substr($parameter, 0, 2) === '--'
            ){
                $parameter = substr($parameter, 2);
                $value = true;
                $tmp = explode('=', $parameter, 2);
                if(array_key_exists(1, $tmp)){
                    $parameter = $tmp[0];
                    $value = $tmp[1];
                    if(is_numeric($value)){
                        $value = $value + 0;
                    } else {
                        switch($value){
                            case 'true':
                                $value = true;
                                break;
                            case 'false':
                                $value = false;
                                break;
                            case 'null':
                                $value = null;
                                break;
                        }
                    }
                }
                Core::object_set($parameter, $value, $flags, 'child');
            }
        }
        return $flags;
    }

    /**
     * @throws ObjectException
     * @throws Exception
     */
    public static function options($data): mixed
    {
        $options = (object) [];
        foreach($data as $nr => $parameter){
            if(
                is_string($parameter) &&
                substr($parameter, 0, 2) !== '--' &&
                substr($parameter, 0, 1) === '-'
            ){
                $parameter = substr($parameter, 1);
                $value = true;
                $tmp = explode('=', $parameter, 2);
                if(array_key_exists(1, $tmp)){
                    $parameter = $tmp[0];
                    $value = $tmp[1];
                    if(is_numeric($value)){
                        $value = $value + 0;
                    } else {
                        switch($value){
                            case 'true':
                                $value = true;
                                break;
                            case 'false':
                                $value = false;
                                break;
                            case 'null':
                                $value = null;
                                break;
                        }
                    }
                }
                Core::object_set($parameter, $value, $options, 'child');
            }
        }
        return $options;
    }

    /**
     * @throws Exception
     */
    public function select($attribute='', $criteria=[]): array
    {
        $find = [];
        if(empty($attribute)){
            return $find;
        }
        if($this->has($attribute) === false){
            return $find;
        }
        $data = $this->get($attribute);
        if(empty($data)){
            return $find;
        }
        if(!is_array($data)){
            return $find;
        }
        foreach($data as $value){
            $select = new Data($value);
            foreach($criteria as $option_key => $option_value){
                if($select->has($option_key) === false){
                    continue;
                }
                if($select->get($option_key) === $option_value){
                    $find[] = $value;
                }
            }
        }
        return $find;
    }

    /**
     * @throws Exception
     */
    public function get($attribute=''): mixed
    {
        return $this->data('get', $attribute);
    }

    /**
     * @throws Exception
     */
    public function set($attribute='', $value=null, $is_debug=false): mixed
    {
        $part_before = stristr($attribute, '[]', true);
        $part_after = stristr($attribute, '[]');
        if($part_before !== false){
            $attribute = $part_before;
            $attribute .= '.' . $this->index($attribute);
//            d($attribute);
        }
        $explode = explode('[', $attribute, 2);
        if(array_key_exists(1, $explode)){
            $attribute = $explode[0];
            $temp = explode('][', substr($explode[1], 0, -1));
            foreach($temp as $nested_attribute_key){
                if(substr($nested_attribute_key, 0, 1) === '$'){
                    $nested_attribute_value = $this->get(substr($nested_attribute_key, 1));
                } else {
                    $nested_attribute_value = $nested_attribute_key;
                }
                $attribute .= '.' . $nested_attribute_value;
            }
        }
        if(!empty($part_after)){
//            ddd($part_after);
//            $attribute .= '.'
        }
        return $this->data('set', $attribute, $value, $is_debug);
    }

    /**
     * @throws Exception
     */
    public function delete($attribute=''): bool
    {
        return $this->data('delete', $attribute);
    }

    /**
     * @throws Exception
     */
    public function has($attribute=''): bool
    {
        return Core::object_has($attribute, $this->data());
    }

    /**
     * @throws Exception
     */
    public function has_property($attribute=''): bool
    {
        return Core::object_has_property($attribute, $this->data());
    }

    /**
     * @throws Exception
     */
    public function extract($attribute=''): mixed
    {
        //add first & last
        $get = $this->get($attribute);
        $delete = $this->delete($attribute);
        return $get;
    }

    public function is_debug($is_debug=false): void
    {
        $this->is_debug = $is_debug;
    }

    /**
     * @throws Exception
     */
    public function data($attribute=null, $value=null, $type=null, $is_debug=false): mixed
    {
        if(is_int($attribute)){
            $attribute = (string) $attribute;
        }
        if($attribute !== null){
            if($attribute == 'set'){
                if(
                    $value === null &&
                    $type === null
                ){
                    $this->data = null;
                } else {
                    if(is_int($value)){
                        $value = (string) $value;
                    }
                    $do_not_nest_key = $this->do_not_nest_key();
                    if($do_not_nest_key){
                        $this->data->{$value} = $type;
                        return $this->data->{$value};
                    } else {
                        Core::object_delete($value, $this->data()); //for sorting an object
                        Core::object_set($value, $type, $this->data(), 'child');
                        return Core::object_get($value, $this->data());
                    }
                }
            }
            elseif($attribute == 'get'){
                return Core::object_get($value, $this->data(), $this->is_debug);
            }
            elseif($attribute == 'has'){
                return Core::object_has($value, $this->data());
            }
            elseif($attribute == 'has_property'){
                return Core::object_has_property($value, $this->data());
            }
            elseif($attribute == 'has.property'){
                return Core::object_has_property($value, $this->data());
            }
            elseif($attribute === 'extract'){
                return $this->extract($value);
            }
            if($value !== null){
                if(
                    in_array(
                        $attribute,
                        [
                            'delete',
                            'remove'
                        ],
                        true
                    )
                ){
                    return $this->deleteData($value);
                } else {
                    if(is_int($attribute)){
                        $attribute = (string) $attribute;
                    }
                    Core::object_delete($attribute, $this->data()); //for sorting an object
                    Core::object_set($attribute, $value, $this->data());
                    return null;
                }
            } else {
                if(is_int($attribute)){
                    $attribute = (string) $attribute;
                }
                if(is_string($attribute)){
                    return Core::object_get($attribute, $this->data(),$this->is_debug);
                }
                elseif(is_object($attribute) && get_class($attribute) === Data::class){
                    $this->setData($attribute->data());
                    return $this->getData();
                }
                else {
                    $this->setData($attribute);
                    return $this->getData();
                }
            }
        }
        return $this->getData();
    }

    private function setData($attribute='', $value=null): void
    {
        if(is_array($attribute) || is_object($attribute)){
            if(is_object($this->data)){
                foreach($attribute as $key => $value){
                    $this->data->{$key} = $value;
                }
            }
            elseif(is_array($this->data)){
                foreach($attribute as $key => $value){
                    $this->data[$key] = $value;
                }
            } else {
                $this->data = $attribute;
            }
        } else {
            if(is_object($this->data)){
                if(is_int($attribute)){
                    $attribute = (string) $attribute;
                }
                $this->data->{$attribute} = $value;
            }
            elseif(is_array($this->data)) {
                $this->data[$attribute] = $value;
            }
        }
    }

    protected function getData($attribute=null): mixed
    {
        if($attribute === null){
            if(is_null($this->data)){
                $this->data = (object) [];
            }
            return $this->data;
        }
        if(isset($this->data[$attribute])){
            return $this->data[$attribute];
        } else {
            return false;
        }
    }

    /**
     * @throws Exception
     */
    private function deleteData($attribute=null): bool
    {
        return Core::object_delete($attribute, $this->data());
    }

    /**
     * @throws Exception
     */
    public function is_empty(): bool
    {
        $data = $this->data();
        if(Core::object_is_empty($data)){
            return true;
        }
        return false;
    }

    /**
     * @throws Exception
     */
    public function clear(): void
    {
        $data = $this->data();
        foreach($data as $key => $unused){
            $this->data('delete', $key);
        }
    }

    /**
     * @throws Exception
     */
    public function copy(): void
    {
        $data = $this->data();
        if(is_array($data)){
            $this->copy = $data;
        } else {
            $this->copy = Core::deep_clone($data);
        }
    }

    /**
     * @throws Exception
     */
    public function reset($to_empty=false): void
    {
        $this->clear();
        if(
            $to_empty === false &&
            $this->copy
        ){
            $this->data($this->copy);
        }
    }

    /**
     * @throws Exception
     */
    public function index($attribute=null): int
    {
        $get = $this->get($attribute);
        $index = 0;
        if(
            is_array($get) ||
            is_object($get)
        ){
            foreach($get as $nr => $unused){
                if(is_numeric($nr)){
                    $index = $nr + 1;
                } else {
                    $index++;
                }
            }
        }
        return $index;
    }

    /**
     * @throws Exception
     */
    public function count($attribute=null): int
    {
        $get = $this->get($attribute);
        $count = 0;
        if(
            is_array($get) ||
            is_object($get)
        ){
            foreach($get as $nr => $unused){
                $count++;
            }
        }
        return $count;
    }


    public function do_not_nest_key($do_not_nest_key=null): ?bool
    {
        if($do_not_nest_key !== null){
            $this->do_not_nest_key = $do_not_nest_key;
        }
        return $this->do_not_nest_key;
    }

    /**
     * @throws Exception
     */
    public function patch_nested_key($data=null, $result=null, $prefix=''): mixed
    {
        if($result === null){
            $result = new Data();
            $result->do_not_nest_key($this->do_not_nest_key());
        }
        if($data === null){
            $data = $this->data();
        }
        foreach($data as $key => $value){
            if(is_scalar($value)){
                if($prefix !== '') {
                    $result->set($prefix . '.' . $key, $value);
                } else {
                    $result->set($key, $value);
                }
            } else {
                foreach($value as $value_key => $value_value){
                    if(is_scalar($value_value)){
                        if($prefix !== '') {
                            $result->set($prefix . '.' . $key . '.' . $value_key, $value_value);
                        } else {
                            $result->set($key . '.' . $value_key, $value_value);
                        }
                    } else {
                        if($prefix !== ''){
                            $this->patch_nested_key($value_value, $result, $prefix . '.' . $key . '.' . $value_key);
                        } else {
                            $this->patch_nested_key($value_value, $result, $key . '.' . $value_key);
                        }
                    }
                }
            }
        }
        return $result->data();
    }

    /**
     * @throws ObjectException
     * @throws FileWriteException
     * @throws Exception
     * @throws DirectoryCreateException
     */
    public function write($url='', $options=[]): array | bool | int
    {
        $dir = Dir::name($url);
        Dir::create($dir, Dir::CHMOD);
        $is_chown = false;
        if(posix_geteuid() === 0){
            $is_chown = true;
            File::chown($dir, 'www-data', 'www-data');
        }
        $write = false;
        if(is_array($options)){
            $options['return'] = $options['return'] ?? File::SIZE;
            if(array_key_exists('compact', $options) &&
                $options['compact'] === true
            ){
                if(
                    array_key_exists('compress', $options) &&
                    $options['compress'] === true
                ){
                    $options['compress'] = [
                        'algorithm' => 'gz',
                        'level' => 9
                    ];
                }
                elseif(
                    array_key_exists('compress', $options) &&
                    is_array($options['compress']) &&
                    array_key_exists('algorithm', $options['compress']) &&
                    array_key_exists('level', $options['compress'])
                ){
                    //nothing
                } else {
                    $options['compress'] =  [
                        'algorithm' => 'none'
                    ];
                }
                switch(strtolower($options['compress']['algorithm'])) {
                    case 'gz':
                    case 'gzencode':
                        $data = Core::object($this->data(), Core::OBJECT_JSON_LINE);
                        $original_byte = strlen($data);
                        $data = gzencode($data, $options['compress']['level']);
                        if (substr($url, -3) !== '.gz'){
                            $url .= '.gz';
                        }
                        break;
                    case 'gzcompress':
                        $data = Core::object($this->data(), Core::OBJECT_JSON_LINE);
                        $original_byte = strlen($data);
                        $data = gzcompress($data, $options['compress']['level']);
                        if (substr($url, -3) !== '.gz'){
                            $url .= '.gz';
                        }
                        break;
                    case 'none':
                    default:
                        $data = Core::object($this->data(), Core::OBJECT_JSON_LINE);
                        break;
                }
                if($original_byte){
                    $byte = File::write($url, $data, ['return' => File::SIZE]);
                    if($is_chown){
                        File::chown($url, 'www-data', 'www-data');
                    }
                    return [
                        'original' => $original_byte,
                        'byte' => $byte,
                    ];
                } else {
                    $write = File::write($url, $data, $options);
                    if($is_chown){
                        File::chown($url, 'www-data', 'www-data');
                    }
                }
            } else {
                $write = File::write($url, Core::object($this->data(), Core::OBJECT_JSON), $options);
                if($is_chown){
                    File::chown($url, 'www-data', 'www-data');
                }
            }
        }
        elseif(is_string($options)) {
            $write = File::write($url, Core::object($this->data(), Core::OBJECT_JSON), $options);
            if($is_chown){
                File::chown($url, 'www-data', 'www-data');
            }
        }
        return $write;
    }

    /**
     * @throws Exception
     */
    public function remove_null($data = null): void
    {
        if($data = null){
            $data = $this->data();
        }
        if(is_array($data)){
            foreach($data as $key => $value){
                if($value === null){
                    unset($data[$key]);
                }
                elseif(is_object($value)){
                    $this->remove_null($value);
                }
                elseif(is_array($value)){
                    foreach($value as $nr => $value_value){
                        if($value_value === null){
                            unset($value[$nr]);
                        }
                        elseif(is_object($value_value)){
                            $this->remove_null($value_value);
                        }
                    }
                }
            }
        }
        elseif(is_object($data)){
            foreach($data as $key => $value){
                if($value === null){
                    unset($data->{$key});
                }
                elseif(is_object($value)){
                    $this->remove_null($value);
                }
                elseif(is_array($value)){
                    foreach($value as $nr => $value_value){
                        if($value_value === null){
                            unset($value[$nr]);
                        }
                        elseif(is_object($value_value)){
                            $this->remove_null($value_value);
                        }
                    }
                }
            }
        }
    }
}