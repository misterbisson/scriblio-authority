<?php
/*

This is a bastardization of a CSV creating class by Chancey Mathews (foobuilder)


$stuff = new Authority_Csv( 'thingies-'. date( 'r' ) , array( 'thing' , 'stuff' ));
$stuff->add( array( 'thing' => '11' , 'stuff' => '22' ) );
die;

*/


class Authority_Csv
{
	private $_filename;
	private $_columns = array();
	private $_delimiter;
	private $_enclosure;

	public function __construct( $filename = FALSE , $columns = NULL , $delimiter = ',' , $enclosure = '"' )
	{
		// set the easy vars
		$this->_delimiter = (string) $delimiter;
		$this->_enclosure = (string) $enclosure;

		// sanitize the filename
		$filename = (string) str_ireplace( '.csv' , '' , (string) $filename );
		$filename = sanitize_title_with_dashes( trim( $filename ));
		$this->_filename = $filename ? $filename .'.csv' : sanitize_title_with_dashes( date('r')) .'.csv';

		// set the columns (it's important for CSVs to have the same number of columns in every row)
		if( $columns )
			$this->_set_columns( $columns );

	}

	public function add( $data )
	{
		// ensure columns are set, this is a belt and suspenders move
		$this->_set_columns( (array) array_keys( (array) $data ));

		// put all the data into the right columns
		$row = array_fill_keys( $this->_columns , NULL );
		$data = array_intersect_key( (array) $data, (array) $row );
		$row = array_merge( $row, $data );
		$this->_send_line( $row );
	}

	private function _set_columns( $columns )
	{
		// only set the columns the first time
		if( empty( $this->_columns ) && is_array( $columns ))
		{
			$this->_columns = $columns;
			$this->_send_header();
		}
	}

	private function _send_header()
	{
		nocache_headers();
		header('Content-type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'. $this->_filename .'"');

		$this->_send_line( array_map( 'strval' , $this->_columns ));
	}

	private function _send_line( $cells )
	{
		$row = array(); 
		foreach( (array) $cells as $cell )
			$row[] = $this->_enclosure . str_replace( $this->_enclosure , $this->_enclosure . $this->_enclosure , $cell ) . $this->_enclosure;
	
		echo join( $this->_delimiter , $row ) ."\n";
	}

}
