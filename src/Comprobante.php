<?php
/**
 * Interfaz para procesar los comprobantes electronicos
 *  
 * PHP version 7.1
 * 
 * @category  Facturacion-electronica
 * @package   Contica\eFacturacion
 * @author    Josias Martin <josiasmc@emypeople.net>
 * @copyright 2018 Josias Martin
 * @license   https://opensource.org/licenses/MIT MIT
 * @version   GIT: <git-id>
 * @link      https://github.com/josiasmc/facturacion-electronica-cr
 */

namespace Contica\eFacturacion;

use \Nekman\LuhnAlgorithm\Number;
use \Nekman\LuhnAlgorithm\LuhnAlgorithmFactory;
use \GuzzleHttp\Client;
use \GuzzleHttp\Exception;
use \GuzzleHttp\Psr7;

/**
 * Class providing functions to manage electronic invoices
 * 
 * @category Facturacion-electronica
 * @package  Contica\eFacturacion\Comprobantes
 * @author   Josias Martin <josiasmc@emypeople.net>
 * @license  https://opensource.org/licenses/MIT MIT
 * @version  Release: <package-version>
 * @link     https://github.com/josiasmc/facturacion-electronica-cr
 */
class Comprobante
{
    protected $container;
    protected $id; //cedula de empresa
    protected $consecutivo; // consecutivo de comprobante
    protected $clave; // clave generada por este sistema
    protected $tipo; // tipo de comprobante
    protected $datos; // la informacion del comprobante
    protected $situacion; // 1=Normal, 2=Contingencia, 3=Sin Internet
    protected $estado; // el estado: 1=Pendiente, 2=Enviado, 3=Confirmado
                        //4=Aceptado, 5=AceptadoParcialmente, 6=Rechazado

    /**
     * Constructor for the Comprobantes
     * 
     * @param array $container El contenedor con los ajustes
     * @param array $datos     Los datos del comprobante a crear
     */
    public function __construct($container, $datos)
    {
        $empresas = new Empresas($container);
        date_default_timezone_set('America/Costa_Rica');
        $id = $datos['Emisor']['Identificacion']['Numero'];
        if (!$empresas->exists($id)) {
            throw new \Exception('El emisor no esta registrado');
        };
        $this->id = $id;
        $this->container = $container;
        $this->container['id'] = $id;
        $this->consecutivo = $datos['NumeroConsecutivo'];
        $this->tipo = substr($this->consecutivo, 8, 2);
        $this->situacion = 1; //Normal
        //TO DO: Codigo para detectar factura por contingencia
        $this->estado = 1; //Pendiente
        $clave = $this->_generarClave();
        //echo 'Clave: ' . $clave . "\n";//-----------------
        $this->clave = $clave;
        $this->datos = array_merge(['Clave' => $clave], $datos);
    }

    /**
     * Procesador de envios
     * 
     * @return bool
     */
    public function enviar()
    {
        $datos = $this->datos;
        $db = $this->container['db'];
        $creadorXml = new CreadorXML($this->container);
        $xml = $creadorXml->crearXml($datos);

        // Enviar el comprobante a Hacienda
        $post = [
            'clave' => $datos['Clave'],
            'fecha' => $datos['FechaEmision'],
            'emisor' => [
                'tipoIdentificacion' => $datos['Emisor']['Identificacion']['Tipo'],
                'numeroIdentificacion' => $datos['Emisor']['Identificacion']['Numero']
            ],
            'receptor' => [
                'tipoIdentificacion' => $datos['Receptor']['Identificacion']['Tipo'],
                'numeroIdentificacion' => $datos['Receptor']['Identificacion']['Numero']
            ],
            'comprobanteXml' => base64_encode($xml)
        ];
        $token = new Token($this->id, $this->container);
        $token = $token->getToken();
        $estado = 1; //Pendiente
        if ($token) {
            // Hacer un envio solamente si logramos recibir un token
            $sql  = 'SELECT Ambientes.URI_API '.
            'FROM Ambientes '.
            'LEFT JOIN Empresas ON Empresas.Id_ambiente_mh = Ambientes.Id_ambiente '.
            'WHERE Empresas.Cedula = ' . $this->id;
            $uri = $db->query($sql)->fetch_assoc()['URI_API'] . 'recepcion';
            //echo "\nURL: $uri \n";
            $client = new Client(
                ['headers' => ['Authorization' => 'bearer ' . $token]]
            );
            //echo "Listo para hacer el post.\n\n";

            try {
                $res = $client->post($uri, ['json' => $post]);
                $code = $res->getStatusCode();
                echo "\nRespuesta: $code\n";
                if ($code == 201 || $code == 202) {
                    $this->estado = 2; //enviado
                }
            } catch (Exception\ClientException $e) {
                // a 400 level exception occured
                // cuando ocurre este error, el comprobante se guarda 
                // de forma normal, para ser enviado en un futuro.
                $res = $e->getResponse();
                echo Psr7\str($res);
                //echo 'Respuesta: ' . $res->getStatusCode()."\n";
            } catch (Exception\ConnectException $e) {
                // a connection problem
                echo 'Error de conexion';
                $this->situacion = 3; //sin internet
            };
        } else {
            $this->situacion = 3; //sin internet (no pudimos conseguir token)
        }

        if ($this->situacion == 3) {
            // No hay internet, tenemos que cambiarle la clave
            $cl = $this->_generarClave();
            $datos['Clave'] = $cl;
            $this->clave = $cl;
            $this->datos = $datos;
            $xml = $creadorXml->crearXml($datos);
        }
                
        // Guardar el comprobante
        /*$file = fopen(__DIR__ . "/inv.xml", "w");
        fwrite($file, $xml);
        fclose($file);*/
        $xmldb = $db->real_escape_string($xml);
        $cl = $this->clave;
        $sql = "INSERT INTO Emisiones ".
        "(Clave, Cedula, Estado, xmlFirmado) VALUES ".
        "(" . $cl . ", " . $this->id . ", ". 
        $this->estado . ", '" . $xmldb . "')";
        $db->query($sql);
        echo $db->error."\n";
        return $this->clave;
    }

    /**
     * Coger la clave
     * 
     * @return string La clave de este comprobante
     */
    public function cogerClave()
    {
        return $this->clave;
    }

    /**
     * Generador de clave numerica
     * 
     * @return string La clave numerica
     */
    private function _generarClave()
    {
        $luhn = LuhnAlgorithmFactory::create();
        $pais = '506';
        $fecha = date('dmy');
        $cedula = str_pad($this->id, 12, '0', STR_PAD_LEFT);
        $consecutivo = $this->consecutivo;
        $situacion = $this->situacion;
        $codigo = $luhn->calcCheckDigit(new Number($pais));
        $codigo .= $luhn->calcCheckDigit(new Number($fecha));
        $codigo .= $luhn->calcCheckDigit(new Number($cedula));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, 0, 3)));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, 3, 5)));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, 8, 2)));
        $codigo .= $luhn->calcCheckDigit(new Number(substr($consecutivo, -10)));
        $codigo .= $luhn->calcCheckDigit(new Number($situacion));
        $clave =  $pais . $fecha . $cedula . $consecutivo . $situacion . $codigo;
        if (!(strlen($clave) === 50)) {
            throw new \Exception('La clave no tiene la correcta longitud');
        }
        return $clave;
    }
}