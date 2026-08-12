<?php
// extends PDO
// Copia este archivo a acceso_sodimac_db.php y completa tus credenciales
class db extends PDO
{
	public $nom_DB = '';
	private $con_db = NULL;
	public function __construct()
	{
		$this->nom_DB = 'TU_BD_SODIMAC';
	}
	public function set_dbname($nombre)
	{
		$this->nom_DB = $nombre;
	}
	public function conectar()
	{
		if($this->nom_DB == '')
		{
			return 'Error debe indicar nombre de la DB';
		}
		$this->con_db = NULL;
		try
		{
			if(empty(PDO::getAvailableDrivers()))
			{
				throw new PDOException ("ERROR - PDO driver PDO NO soportado");
			}
			else
			{
				if(!is_array(PDO::getAvailableDrivers()))
				{
					throw new PDOException("ERROR - Carga de Driver PDO");
				}
				else
				{
					if(in_array('mysql', PDO::getAvailableDrivers()))
					{
						$this->con_db = new PDO("mysql:host=TU_HOST:3306;dbname=".$this->nom_DB,"TU_USUARIO","TU_PASSWORD");
						if(!$this->con_db)
						{
							throw new PDOException("ERROR - No se pudo establecer conexión con la DB");
						}
					}
					else
					{
						throw new PDOException("ERROR - Driver PDO para mysql NO Ubicado");
					}
				}
			}
		}
		catch(PDOException $e)
		{
			return($e->getMessage());
		}
		return 'OK';
	}
	public function getConexion()
	{
		return $this->con_db;
	}
	public function procesar($sql, $formato= '') 
	{
		$resultado = array();
		try
 		{
			$query = $this->con_db->prepare($sql);
			$query->execute();
			$error = $query->errorInfo();
			if($error[2]=='')
			{
				switch ($formato) 
				{
					case 'array':
						$resultado['data'] = $query->fetchAll(PDO::FETCH_ASSOC);
						break;
					case 'objeto':
						$resultado['data'] = $query->fetchAll(PDO::FETCH_OBJ);
						break;
					case 'archivo':
						$resultado['data'] = $query->fetch(PDO::PARAM_LOB);//
						//$query->bindColumn('document_dte_01', $resultado, \PDO::FETCH_BOUND);
						//$query->fetch(\PDO::FETCH_BOUND);
						break;
					default: 
						$resultado['data'] = $query->fetchAll(PDO::FETCH_BOTH );
				}
			}
			else
			{
				$resultado['error'] = 'Sentencia SQL no valida';
				$resultado['detalle'] = $error[2];
			}
		}
		catch(PDOException $e) 
		{
			error_log( $e->getMessage() ); 
			$resultado['error'] = ' Sentencia SQL no valida';
			$resultado['detalle'] = $e->getMessage();
 		}
		return $resultado;
	}
}
