<?php

namespace Ddeboer\Imap\Message;

use Ddeboer\Imap\Message;
use Ddeboer\Imap\Parameters;
use Ddeboer\Imap\Exception\UnknownEncodingException;
use Exception;

/**
 * A message part
 */
class Part implements \RecursiveIterator
{
    const PART_TYPE_ATTACHMENT = 'attachment';
    const PART_TYPE_INLINE = 'inline';
    
    const TYPE_TEXT = 'text';
    const TYPE_MULTIPART = 'multipart';
    const TYPE_MESSAGE = 'message';
    const TYPE_APPLICATION = 'application';
    const TYPE_AUDIO = 'audio';
    const TYPE_IMAGE = 'image';
    const TYPE_VIDEO = 'video';
    const TYPE_OTHER = 'other';
    const TYPE_UNKNOWN = 'unknown';

    //http://www.w3.org/Protocols/rfc1341/5_Content-Transfer-Encoding.html
    const ENCODING_7BIT = '7bit';
    const ENCODING_8BIT = '8bit';
    const ENCODING_BINARY = 'binary';
    const ENCODING_BASE64 = 'base64';
    const ENCODING_QUOTED_PRINTABLE = 'quoted-printable';
    const ENCODING_UNKNOWN = 'unknown';

    const SUBTYPE_PLAIN = 'plain';
    const SUBTYPE_TEXT = 'text';
    const SUBTYPE_HTML = 'html';

    protected $typesMap = array(
        0 => self::TYPE_TEXT,
        1 => self::TYPE_MULTIPART,
        2 => self::TYPE_MESSAGE,
        3 => self::TYPE_APPLICATION,
        4 => self::TYPE_AUDIO,
        5 => self::TYPE_IMAGE,
        6 => self::TYPE_VIDEO,
        7 => self::TYPE_OTHER
    );

    protected $encodingsMap = array(
        0 => self::ENCODING_7BIT,
        1 => self::ENCODING_8BIT,
        2 => self::ENCODING_BINARY,
        3 => self::ENCODING_BASE64,
        4 => self::ENCODING_QUOTED_PRINTABLE,
        5 => self::ENCODING_UNKNOWN,
        6 => self::ENCODING_QUOTED_PRINTABLE,// for case "quoted/printable"
        7 => self::ENCODING_QUOTED_PRINTABLE,// for case "quoted/printable"
    );

    /**
     * @var boolean
     */
    protected $keepUnseen = true;

    protected $type;

    protected $subtype;

    protected $encoding;

    protected $bytes = 0;

    protected $lines;

    /**
     * @var Parameters
     */
    protected $parameters;

    protected $stream;

    protected $messageNumber;

    protected $partNumber;

    protected $structure;

    protected $content;

    protected $decodedContent;

    protected $parts = array();

    protected $key = 0;

    protected $disposition;

    private $lastException;

    /**
     * Constructor
     *
     * @param resource  $stream        IMAP stream
     * @param int       $messageNumber Message number
     * @param int       $partNumber    Part number (optional)
     * @param \stdClass $structure     Part structure
     */
    public function __construct(
        $stream,
        $messageNumber,
        $partNumber = null,
        \stdClass $structure = null
    ) {
        $this->stream = $stream;
        $this->messageNumber = $messageNumber;
        $this->partNumber = $partNumber;
        $this->structure = $structure;
        $this->parseStructure($structure);
    }
    /**
     * Prevent the message from being marked as seen
     *
     * Defaults to false, so messages that are read will be marked as seen.
     *
     * @param bool $bool
     *
     * @return self
     */
    public function keepUnseen($bool = true)
    {
        $this->keepUnseen = (bool) $bool;

        return $this;
    }

    public function getCharset()
    {
        return $this->parameters->get('charset');
    }

    public function getType()
    {
        return $this->type;
    }

    public function getSubtype()
    {
        return $this->subtype;
    }

    public function getEncoding()
    {
        return $this->encoding;
    }

    /**
     * @return int
     */
    public function getBytes()
    {
        return $this->bytes;
    }

    public function getLines()
    {
        return $this->lines;
    }

    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * Get raw part content
     *
     * @return string
     */
    public function getContent()
    {
        if (null === $this->content) {
            $this->content = $this->doGetContent($this->keepUnseen);
        }

        return $this->content;
    }

    /**
     * Get decoded part content
     *
     * @return string
     */
    public function getDecodedContent()
    {
        if (null === $this->decodedContent) {
            switch ($this->getEncoding()) {
                case self::ENCODING_BASE64:
                    $this->decodedContent = base64_decode($this->getContent());
                    break;
                case self::ENCODING_QUOTED_PRINTABLE:
                    $this->decodedContent =  quoted_printable_decode($this->getContent());
                    break;
                case self::ENCODING_7BIT:
                case self::ENCODING_8BIT:
                case self::ENCODING_BINARY:
                    $this->decodedContent = $this->getContent();
                    break;
                default:
                    throw new UnknownEncodingException($this->messageNumber, $this->getEncoding());
            }
        }

        return $this->decodedContent;
    }

    public function getStructure()
    {
        return $this->structure;
    }

    protected function fetchStructure($partNumber = null)
    {
        if (null === $this->structure) {
            $this->loadStructure();
        }

        if ($partNumber) {
            return $this->structure->parts[$partNumber];
        }

        return $this->structure;
    }

    protected function parseStructure(\stdClass $structure)
    {
        $type = strtolower($structure->type);
        if (isset($this->typesMap[$type])) {
            $this->type = $this->typesMap[$type];
        } else {
            $this->type = self::TYPE_UNKNOWN;
        }

        if(array_key_exists($structure->encoding,$this->encodingsMap)){
            $this->encoding = $this->encodingsMap[$structure->encoding];
        }

        $this->subtype = strtolower($structure->subtype);

        if (isset($structure->bytes)) {
            $this->bytes = (int)$structure->bytes;
        }

        foreach (array('disposition','description') as $optional) {
            if (isset($structure->$optional)) {
                $this->$optional = $structure->$optional;
            }
        }

        $this->parameters = new Parameters();
        if (is_array($structure->parameters)) {
            $this->parameters->add($structure->parameters);
        }

        if (isset($structure->dparameters)) {
            $this->parameters->add($structure->dparameters);
        }

        if (isset($structure->parts)) {
            foreach ($structure->parts as $key => $partStructure) {
                if (null === $this->partNumber) {
                    $partNumber = ($key + 1);
                } else {
                    $partNumber = (string) ($this->partNumber . '.' . ($key+1));
                }

                if ($this->getDispositionType($partStructure)) {
                    $part = new Attachment($this->stream, $this->messageNumber, $partNumber, $partStructure);
                } else {
                    $part = new Part($this->stream, $this->messageNumber, $partNumber, $partStructure);
                }
                $part->keepUnseen($this->keepUnseen);
                $this->parts[] = $part;
            }
        }
    }

    /**
     * Get an array of all parts for this message
     *
     * @return self[]
     */
    public function getParts()
    {
        return $this->parts;
    }

    public function current()
    {
        return $this->parts[$this->key];
    }

    public function getChildren()
    {
        return $this->current();
    }

    public function hasChildren()
    {
        return count($this->parts) > 0;
    }

    public function key()
    {
        return $this->key;
    }

    public function next()
    {
        ++$this->key;
    }

    public function rewind()
    {
        $this->key = 0;
    }

    public function valid()
    {
        return isset($this->parts[$this->key]);
    }

    public function getDisposition()
    {
        return $this->disposition;
    }

    /**
     * Get raw message content
     *
     * @param bool $keepUnseen Whether to keep the message unseen.
     *                         Default behaviour is set set the seen flag when
     *                         getting content.
     *
     * @return string
     */
    protected function doGetContent($keepUnseen = false)
    {
        return imap_fetchbody(
            $this->stream,
            $this->messageNumber,
            $this->partNumber ?: 1,
            \FT_UID | ($keepUnseen ? \FT_PEEK : null)
        );
    }

    /**
     * @param $part
     * @return null|string
     */
    private function getDispositionType($part)
    {
        // Attachment with correct Content-Disposition header

        if (isset($part->disposition)) {
            $disposition = strtolower($part->disposition);
            if ('attachment' === $disposition) {
                return self::PART_TYPE_ATTACHMENT;
            }elseif('inline' === $disposition){
                $subtype = strtolower($part->subtype);
                if($subtype == self::SUBTYPE_PLAIN || $subtype == self::SUBTYPE_HTML){
                    return null;
                }
                return self::PART_TYPE_INLINE;
            }
            return self::PART_TYPE_ATTACHMENT;
        }

        // Attachment without Content-Disposition header
        if (isset($part->parameters)) {
            foreach ($part->parameters as $parameter) {
                if ('name' === strtolower($parameter->attribute)
                    || 'filename' === strtolower($parameter->attribute)
                ) {
                    return self::PART_TYPE_ATTACHMENT;
                }
            }
        }

        return null;
    }

    public function debugParts($pref = '')
    {
        $res = sprintf("%s%s %s/%s\n",$pref,get_class($this),$this->getType(),$this->getSubType());
        $pref .= '    ';

        foreach($this->parts as $part){
            $res .= $part->debugParts($pref);
        }

        return $res;
    }

    protected function getLastException()
    {
        return $this->lastException;
    }

    protected function setLastException(Exception $e = null)
    {
        $this->lastException = $e;
    }
}
