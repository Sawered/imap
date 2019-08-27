<?php

namespace Ddeboer\Imap\Message;

use Ddeboer\Imap\Parameters;
use Ddeboer\Transcoder\Transcoder;
use Ddeboer\Transcoder\MBTranscoder;

use DateTime;
use DetectCyrillic\Encoding;
use Exception;
/**
 * Collection of message headers
 */
class ExtendedHeaders extends Parameters
{
    
    public static function fromString(string $headersText)
    {
        //некоторые умники не кодируют тему письма, и кодировка заголовков
        //не 7/8 бит и не UTF-8 после парсинга заголовков восстановить
        //кодировку уже не получится, поэтому попытаемся это сделать в самом начале
        $headersText = static::fixEncoding($headersText);
        $headers = static::parse($headersText);
       
        return static::fromRawHeaders($headers);
    }
    
    public static function fromRawHeaders(array $headers)
    {
        $multiple = ['received'];
        $parameters = [];
        foreach($headers as $k => $item){
            $name = strtolower($item['name']);
            $value = static::parseHeader($name,$item['value']);

            if(isset($parameters[$name])){
                if(!is_array($parameters[$name])){
                    $parameters[$name] = array($parameters[$name]);
                }
                $parameters[$name][] = $value;
                continue;
            }

            if(in_array($name,$multiple)){

                $parameters[$name][] = $value;
            }else{
                $parameters[$name] = $value;
            }
        }
        
        $obj = new static();
        $obj->setParameters($parameters);
        return $obj;
    }
    
    protected function setParameters(array $parameters)
    {
        $this->parameters = $parameters;
    }

    /**
     * Get header
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get($key)
    {
        return parent::get(strtolower($key));
    }

    /**
     * Function returns headers hronological from first to latest
     * header values not parsed at all
     *
     * @return array
     **/
    public static function parse($headersText)
    {
        $regexp = "@^([a-zA-Z\-]+):[[:space:]]{1,}@mi";
        $lineFolding = "@[\r\n][[:space:]]{1,}@mi";

        $matches = [];
        if(!preg_match_all($regexp,$headersText,$matches,PREG_OFFSET_CAPTURE)){
            return array();
        }
        //do not use unicode functions

        $headers = [];
        $end = null;
        $len = strlen($headersText);
        while($match = array_pop($matches[0])){
            if(is_null($end)){
                $full_header = substr($headersText,$match[1]);
            }else{
                $end = $end-$match[1];
                $full_header = substr($headersText,$match[1],$end);
            }
            //cut off line folding
            $value = substr($full_header,strlen($match[0]));
            $value = preg_replace($lineFolding,' ',$value);
            $value = trim($value);

            $headers[] = array(
                'name' => trim($match[0],": \r\n"),
                'value' => $value,
            );
            $end = $match[1] ;
        }

        return $headers;
    }

    public static function parseHeader(string $name, $value)
    {
        if($name == 'from'
            || $name == 'sender'
            || $name == 'reply-to'
            || $name == 'to'
            || $name == 'cc'
            || $name == 'bcc'
        ){
            if(strpos($value,'undisclosed-recipients') !==false){
                return null;
            }
            $items = static::parseAddrList($value);

            foreach($items as $k =>$item){
                if(!property_exists($item,'host') || $item->host === '.SYNTAX-ERROR.'){
                    unset($items[$k]);
                    continue;
                }
                $items[$k] = static::decodeEmailAddress($item);
            }

            if($name == 'from'
                || $name == 'sender'
                || $name == 'reply-to'
            ){
                return empty($items)?null:reset($items);
            }
            return $items;
        }

        if($name == 'received'){
            return static::parseReceivedHeader($value);
        }

        if($name == 'return-path'){
            return preg_replace('/.*<([^<>]+)>.*/','$1',$value);
        }

        if($name == 'subject'){
            return static::decode($value);
        }

        if($name == 'date'){
            return static::decodeDate($value);
        }
        
        return $value;
    }

    protected static function parseAddrList($value)
    {
        $items = imap_rfc822_parse_adrlist($value,'nodomain');

        //as alternative we can use mailparse_rfc822_parse_addresses

        return $items;
    }


    private static function decodeEmailAddress($value)
    {

        $mailbox = property_exists($value,'mailbox')?$value->mailbox:null; //sometimes property is not exists
        $host = property_exists($value,'host') ? $value->host : null;
        $personal = property_exists($value, 'personal') ? static::decode($value->personal) : null;


        return new EmailAddress(
            $mailbox,
            $host,
            $personal
        );
    }

    protected static function parseReceivedHeader($value)
    {
        // received    =  "Received"    ":"            ; one per relay
        //                   ["from" domain]           ; sending host
        //                   ["by"   domain]           ; receiving host
        //                   ["via"  atom]             ; physical path
        //                  *("with" atom)             ; link/mail protocol
        //                   ["id"   msg-id]           ; receiver msg id
        //                   ["for"  addr-spec]        ; initial form
        //                    ";"    date-time         ; time received}

        $result = [];
        if(strpos($value,';') !== false){
            list($value,$date) = static::splitReceivedDate($value);
            if($date){
                $result['date'] = static::decodeDate($date);
            }
        }

        $regex = "@([[:space:]](from|via|with|id|by|for)(?:[[:space:]]))@mi";

        $value = ' '.$value; //hint for regexp
        $matches = [];
        if(!preg_match_all($regex,$value,$matches,PREG_OFFSET_CAPTURE)){
            return $result;
        }

        $end = strlen($value);
        while($match = array_pop($matches[0])){
            $keyLength = strlen($match[0]);
            $end = is_null($end)?null:$end-$match[1]-$keyLength;
            $part = substr($value,$match[1]+$keyLength,$end);

            $key = trim($match[0]);
            $part = trim($part);

            if($key == 'for'){
                $part = preg_replace('/.*<([^<>]+)>.*/','$1',$part);
            }

            if( isset($result[$key])){
                if(!is_array($result[$key])){
                    $result[$key] = array($result[$key]);
                }
                $result[$key][] = $part;
            }else{
                $result[$key] = $part;
            }

            $end = $match[1] ;
        }

        return $result;
    }

    protected static function splitReceivedDate(string $value)
    {
        $len = strlen($value);
        $lastPos =  strrpos($value,';');
        if($lastPos ===false){
            return [$value,null];
        }

        $date = substr($value,$lastPos+1);
        $other = substr($value,0,$len - 1 - strlen($date) );
        return [$other,$date];
    }

    protected static function decodeDate($value)
    {
        $value = static::decode($value);

        if(empty($value)){
            return null;
        }

        $value =  preg_replace('/([^\(]*)\(.*\)/', '$1', $value);
        $value =  preg_replace('/(UT|UCT)(?!C)/','UTC',$value);

        try{
            return new DateTime($value);
        }catch(Exception $e){
            return null;
        }
    }

    /**
     * Tries to right decode wrong coded header fields like subject
     *
     * @return string
     **/
    public static function fixEncoding(string $content)
    {
        $charset = static::getCharset($content);

        if(!is_null($charset)){

            if(in_array($charset, ['ansi'])) {
                $charset = static::getCursetDetector()->detectMaxRelevant($content);
            }

            $charset = strtolower(trim($charset));

            try{
                $content =  Transcoder::create()->transcode(
                    $content,
                    $charset,
                    'UTF-8'
                );
                $content = MBTranscoder::removeInvalidUTF8Bytes($content);
            }catch(RuntimeException $e){
                return $content;
            }
        }
        return $content;
    }

    protected static function getCharsetDetector()
    {
        static $charsetDetector = null;

        if(is_null($charsetDetector)) {
            $charsetDetector = new Encoding();
        }

        return $charsetDetector;
    }

    /**
     * tries to find the charset of message
     *
     * @return string
     * @author skoryukin
     **/
    protected static function getCharset(string $content)
    {
        $matches = [];
        $charset = null;

        if(preg_match('@^Content-Type:.*charset=([^;=[:space:]]+)[;]*@mi',$content,$matches)){
            $charset = $matches[1];
        }elseif(preg_match('@^Content.*charset=([^;=[:space:]]+)[;]*@mi',$content,$matches)){
            $charset = $matches[1];
        }

        return trim($charset, "'\"");
    }
}
