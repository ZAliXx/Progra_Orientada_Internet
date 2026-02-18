<?php
class User {
    private $conn;
    private $table = "usuarios";

    public $id;
    public $nombre;
    public $email;
    public $monedas;
    public $nivel_boost;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function crear() {
        $query = "INSERT INTO " . $this->table . " 
                  SET nombre=:nombre, email=:email, password=:password";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":nombre", $this->nombre);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":password", password_hash($this->password, PASSWORD_DEFAULT));
        
        return $stmt->execute();
    }

    public function actualizarMonedas($tiempo_conectado) {
        $monedas_ganadas = floor($tiempo_conectado / 5); // 1 moneda cada 5 segundos
        $query = "UPDATE " . $this->table . " 
                  SET monedas = monedas + :monedas 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":monedas", $monedas_ganadas);
        $stmt->bindParam(":id", $this->id);
        
        return $stmt->execute();
    }
}
?>