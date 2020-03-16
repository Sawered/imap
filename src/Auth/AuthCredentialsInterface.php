<?php


namespace Ddeboer\Imap\Auth;


interface AuthCredentialsInterface
{

    /**
     * @return string|null
     */
    public function getUsername();

    /**
     * @return string|null
     */
    public function getPassword();

    /**
     * @return string
     */
    public function getType();

    /**
     * @return string[]
     */
    public function getFallBackTypes(): array;
}