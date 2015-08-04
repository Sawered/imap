<?php

namespace Ddeboer\Imap;

use Ddeboer\Imap\Exception\MessageDoesNotExistException;
use Ddeboer\Imap\Message\EmailAddress;
use Ddeboer\Imap\Exception\MessageDeleteException;
use Ddeboer\Imap\Exception\MessageMoveException;

/**
 * An IMAP message (e-mail)
 */
class Message extends Message\Part
{
    private $headers;
    private $extHeaders;
    private $attachments;

    /**
     * @var boolean
     */
    private $keepUnseen = false;

    /**
     * Constructor
     *
     * @param resource $stream        IMAP stream
     * @param int      $messageNumber Message number
     */
    public function __construct($stream, $messageNumber)
    {
        $this->stream = $stream;
        $this->messageNumber = $messageNumber;

        $this->loadStructure();
    }


    /**
     * Get message id
     *
     * A unique message id in the form <...>
     *
     * @return string
     */
    public function getId()
    {
        return $this->getHeaders()->get('message_id');
    }

    public function getUid()
    {
        return $this->messageNumber;
    }

    /**
     * Get message sender (from headers)
     *
     * @return EmailAddress
     */
    public function getFrom()
    {
        return $this->getExtendedHeaders()->get('from');
    }

    /**
     * Get message sender (from headers)
     *
     * @return EmailAddress
     */
    public function getSender()
    {
        return $this->getExtendedHeaders()->get('sender');
    }

    /**
     * Get To recipients
     *
     * @return EmailAddress[] Empty array in case message has no To: recipients
     */
    public function getTo()
    {
        return $this->getExtendedHeaders()->get('to') ?: [];
    }

    /**
     * Get CC recipients
     *
     * @return EmailAddress[] Empty array in case message has no CC: recipients
     */
    public function getCc()
    {
        return $this->getExtendedHeaders()->get('cc') ?: [];
    }

    /**
     * Get BCC recipients
     *
     * @return EmailAddress[] Empty array in case message has no BCC: recipients
     */
    public function getBcc()
    {
        return $this->getExtendedHeaders()->get('bcc') ?: [];
    }

    /**
     * Get message number (from headers)
     *
     * @return int
     */
    public function getNumber()
    {
        return $this->messageNumber;
    }

    /**
     * Get date (from headers)
     *
     * @return \DateTime
     */
    public function getDate()
    {
        return $this->getHeaders()->get('date');
    }

    /**
     * Get message size (from headers)
     *
     * @return int
     */
    public function getSize()
    {
        return $this->getHeaders()->get('size');
    }

    /**
     * Get raw part content
     *
     * @return string
     */
    public function getContent()
    {
        // Null headers, so subsequent calls to getHeaders() will return
        // updated seen flag
        $this->headers = null;

        return $this->doGetContent($this->keepUnseen);
    }

    /**
     * Get message answered flag value (from headers)
     *
     * @return boolean
     */
    public function isAnswered()
    {
        return $this->getHeaders()->get('answered');
    }

    /**
     * Get message deleted flag value (from headers)
     *
     * @return boolean
     */
    public function isDeleted()
    {
        return $this->getHeaders()->get('deleted') == 'D';
    }

    /**
     * Get message draft flag value (from headers)
     *
     * @return boolean
     */
    public function isDraft()
    {
        return $this->getHeaders()->get('draft') == 'X';
    }

    /**
     * Has the message been marked as read?
     *
     * @return boolean
     */
    public function isSeen()
    {
        $recent = $this->getHeaders()->get('recent');
        $seen = $this->getHeaders()->get('unseen');

        return 'R' == $recent
            || (strlen($recent)==0  && strlen($seen) ==0);
    }


    public function isRecent()
    {
        return strlen($this->getHeaders()->get('recent')) == 1; //R or N
    }

    /**
     * Get message subject (from headers)
     *
     * @return string
     */
    public function getSubject()
    {
        return $this->getHeaders()->get('subject');
    }

    /**
     * Get message headers with flags (small part of all)
     * Returns only last recipient in from\cc\bcc field - seems this ability is broken
     * inside cclient lib
     *
     * @return Message\Headers
     */
    public function getHeaders()
    {
        if (null === $this->headers) {
            // imap_header is much faster than imap_fetchheader
            // imap_header returns only a subset of all mail headers,
            // but it does include the message flags.
            $headers = imap_header($this->stream, imap_msgno($this->stream, $this->messageNumber));
            $this->headers = new Message\Headers($headers);
        }

        return $this->headers;
    }

    /**
     * Return all headers of message without flags
     * if header present multiple times it will be returned as array
     * from first to last by chronological order
     *
     * @return Message\ExtendedHeaders
     **/
    public function getExtendedHeaders()
    {

        if(is_null($this->extHeaders)){

            //instead of imap_fetchbody we can use
            //$headers = imap_fetchheader($this->stream, $this->messageNumber, \FT_UID);
            $headersText = imap_fetchbody($this->stream, $this->messageNumber, '0', FT_UID);

            $this->extHeaders = new Message\ExtendedHeaders($headersText);
        }

        return $this->extHeaders;
    }

    public function hasBodyHtml()
    {
        return $this->hasPartType(self::SUBTYPE_HTML);
    }

    /**
     * Get body HTML
     *
     * @return string | null Null if message has no HTML message part
     */
    public function getBodyHtml($forcedCharset = null)
    {
        return $this->getBody(self::SUBTYPE_HTML,$forcedCharset);
    }

    /**
     * Get body text
     *
     * @return string
     */
    public function getBodyText($forcedCharset = null)
    {
        return $this->getBody(self::SUBTYPE_PLAIN,$forcedCharset);
    }

    public function hasPartType($type)
    {
        if($this->getSubtype() == $type){
            return true;
        }

        $iterator = new \RecursiveIteratorIterator($this, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $part) {
            if ($part->getSubtype() == $type) {
                return true;
            }
        }
        return false;
    }

    public function getBody($subtype,$forcedCharset)
    {
        if ($this->getSubtype() == $subtype) {
            return $this->getDecodedContent($forcedCharset);
        }

        $iterator = new \RecursiveIteratorIterator($this, \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $part) {
            if ($part->getSubtype() == $subtype) {
                return $part->getDecodedContent($forcedCharset);
            }
        }

        return null;
    }

    public function getRaw()
    {
        return imap_fetchbody($this->stream, $this->messageNumber, "",\FT_UID);
    }

    public function getOverview()
    {
        $res = imap_fetch_overview($this->stream, $this->messageNumber, \FT_UID);
        if(!empty($res)){
            return (array)reset($res);
        }
        return array();
    }
    /**
     * Get attachments (if any) linked to this e-mail
     *
     * @return Message\Attachment[]
     */
    public function getAttachments()
    {
        if (null === $this->attachments) {
            foreach ($this->getParts() as $part) {
                if ($part instanceof Message\Attachment) {
                    $this->attachments[] = $part;
                }
                if ($part->hasChildren()) {
                    foreach ($part->getParts() as $child_part) {
                        if ($child_part instanceof Message\Attachment) {
                            $this->attachments[] = $child_part;
                        }
                    }
                }
            }
        }

        return $this->attachments;
    }

    /**
     * Does this message have attachments?
     *
     * @return int
     */
    public function hasAttachments()
    {
        return count($this->getAttachments()) > 0;
    }

    /**
     * Delete message
     *
     * @throws MessageDeleteException
     */
    public function delete()
    {
        // 'deleted' header changed, force to reload headers, would be better to set deleted flag to true on header
        $this->headers = null;

        if (!imap_delete($this->stream, $this->messageNumber, \FT_UID)) {
            throw new MessageDeleteException($this->messageNumber);
        }
    }

    /**
     * Move message to another mailbox
     * @param Mailbox $mailbox
     *
     * @throws MessageMoveException
     * @return Message
     */
    public function move(Mailbox $mailbox)
    {
        if (!imap_mail_move($this->stream, $this->messageNumber, $mailbox->getName(), \CP_UID)) {
            throw new MessageMoveException($this->messageNumber, $mailbox->getName());
        }

        return $this;
    }

    /**
     * Prevent the message from being marked as seen
     *
     * Defaults to false, so messages that are read will be marked as seen.
     *
     * @param bool $bool
     *
     * @return Message
     */
    public function keepUnseen($bool = true)
    {
        $this->keepUnseen = (bool) $bool;

        return $this;
    }

    /**
     * Load message structure
     */
    private function loadStructure()
    {
        set_error_handler(
            function ($nr, $error) {
                throw new MessageDoesNotExistException(
                    $this->messageNumber,
                    $error
                );
            }
        );

        $structure = imap_fetchstructure(
            $this->stream,
            $this->messageNumber,
            \FT_UID
        );

        restore_error_handler();

        $this->parseStructure($structure);
    }
}
