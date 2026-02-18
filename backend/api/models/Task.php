<?php
class Task {
    private $conn;
    private $table = "tareas";

    public $id;
    public $titulo;
    public $descripcion;
    public $completada;
    public $usuario_id;
    public $grupo_id;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function crear() {
        $query = "INSERT INTO " . $this->table . "
                  SET titulo=:titulo, descripcion=:descripcion, 
                      usuario_id=:usuario_id, grupo_id=:grupo_id";
        
        $stmt = $this->conn->prepare($query);
        
        $stmt->bindParam(":titulo", $this->titulo);
        $stmt->bindParam(":descripcion", $this->descripcion);
        $stmt->bindParam(":usuario_id", $this->usuario_id);
        $stmt->bindParam(":grupo_id", $this->grupo_id);
        
        return $stmt->execute();
    }

    public function marcarCompletada() {
        $query = "UPDATE " . $this->table . " 
                  SET completada = 1 
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":id", $this->id);
        
        return $stmt->execute();
    }
}
?>