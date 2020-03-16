<?php


namespace Ddeboer\Imap\Auth;


class PlainAuth implements AuthCredentialsInterface
{
    /**
     * @var string
     */
    protected $username;
    /**
     * @var string
     */
    protected $password;

    /**
     * PlainAuth constructor.
     * @param string $username
     * @param string $password
     */
    public function __construct($username,$password)
    {

        $this->username = $username;
        $this->password = $password;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getType()
    {
        return "PLAIN";
    }

    public function getFallBackTypes(): array
    {
        return [
            "LOGIN",
            "CRAM-MD5",
        ];
    }

}