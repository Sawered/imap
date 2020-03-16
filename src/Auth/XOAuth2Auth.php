<?php


namespace Ddeboer\Imap\Auth;


class XOAuth2Auth implements AuthCredentialsInterface
{
    /**
     * @var string
     */
    protected $password;

    /**
     * XOAuth2Auth constructor.
     * @param string $username
     * @param string $token
     */
    public function __construct($username, $token)
    {
        $this->password = sprintf("user=%s\001auth=Bearer %s\001\001", $username, $token);
    }

    public function getUsername()
    {
        return null;
    }

    public function getPassword()
    {
        return $this->password;
    }

    public function getType()
    {
        return "XOAUTH2";
    }

    public function getFallBackTypes(): array
    {
        return [];
    }

}