<?php

namespace App\Common;
use PDO;

class Database
{
    private PDO $pdo;

    public function getPDO(){
      return $this->pdo;
  }
    
    protected function __construct()
    {
        $this->pdo = new PDO("mysql:host=db;dbname=tp;charset=utf8mb4", "root", "root");
    }

    static private $instance;
    public static function getInstance () : static {
        // Si on n'a pas d'instance initialisée, on en instancie une
        if ( ! isset( self::$pdo ) )
          self::$instance = new static();
        
        return self::$instance;
      }

}