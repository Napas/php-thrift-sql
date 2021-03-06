<?php

namespace ThriftSQL;

class Hive implements \ThriftSQL {
  private $_host;
  private $_port;
  private $_username;
  private $_password;
  private $_timeout;
  private $_transport;
  private $_client;
  private $_sessionHandle;
  protected $_sasl = true;
  
  public function __construct( $host, $port = 10000, $username = null, $password = null, $timeout = null ) {
    $this->_host = $host;
    $this->_port = $port;
    $this->_username = $username;
    $this->_password = $password;
    $this->_timeout = $timeout;
  }

  public function setSasl( $bool ) {
    $this->_sasl = (bool) $bool;
    return $this;
  }

  public function connect() {
    // Check if we have already connected and have a session
    if ( null !== $this->_sessionHandle ) {
      return $this;
    }
    try {
      $this->_transport = new \Thrift\Transport\TSocket( $this->_host, $this->_port );
      if ( null !== $this->_timeout ) {
        $this->_transport->setSendTimeout( $this->_timeout * 1000 );
        $this->_transport->setRecvTimeout( $this->_timeout * 1000 );
      }
      if ( $this->_sasl ) {
        $this->_transport = new \Thrift\Transport\TSaslClientTransport(
          $this->_transport,
          $this->_username,
          $this->_password
        );
      }
      $this->_transport->open();
      $this->_client = new \ThriftSQL\TCLIServiceClient(
        new \Thrift\Protocol\TBinaryProtocol(
          $this->_transport
        )
      );
      $TOpenSessionReq = new \ThriftSQL\TOpenSessionReq();
      if ( null !== $this->_username && null !== $this->_password ) {
        $TOpenSessionReq->username = $this->_username;
        $TOpenSessionReq->password = $this->_password;
      }
      // Ok, let's try to start a session
      $this->_sessionHandle = $this
        ->_client
        ->OpenSession( $TOpenSessionReq )
        ->sessionHandle;
    } catch( Exception $e ) {
      $this->_sessionHandle = null;
      throw new \ThriftSQL\Exception( $e->getMessage() );
    }
    return $this;
  }

  public function query( $queryStr ) {
    try {
      $queryCleaner = new \ThriftSQL\Utils\QueryCleaner();
      $response = $this->_client->ExecuteStatement( new \ThriftSQL\TExecuteStatementReq( array(
        'sessionHandle' => $this->_sessionHandle,
        'statement' => $queryCleaner->clean( $queryStr ),
        'runAsync' => true,
      ) ) );
      return new \ThriftSQL\HiveQuery( $response, $this->_client );
    } catch ( Exception $e ) {
      throw new \ThriftSQL\Exception( $e->getMessage() );
    }
  }

  public function queryAndFetchAll( $queryStr ) {
    try {
      $query = $this->query( $queryStr );
      $query->wait();
      // Collect results
      $resultTuples = array();
      do {
        $responseTuples = $query->fetch(100);
        // No more data we're done
        if ( empty( $responseTuples ) ) {
          return $resultTuples;
        }
        $resultTuples = array_merge( $resultTuples, $responseTuples );
      } while (true);
    } catch( Exception $e ) {
      throw new \ThriftSQL\Exception( $e->getMessage(), $e->getCode(), $e );
    }
  }

  public function disconnect() {
    // Close session if we have one
    if ( null !== $this->_sessionHandle ) {
      $this->_client->CloseSession( new \ThriftSQL\TCloseSessionReq( array(
        'sessionHandle' => $this->_sessionHandle,
      ) ) );
    }
    $this->_sessionHandle = null;
    // Clear out the client
    $this->_client = null;
    // Close the socket
    if ( null !== $this->_transport ) {
      $this->_transport->close();
    }
    $this->_transport = null;
  }
}
