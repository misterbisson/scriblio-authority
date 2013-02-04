<?php

function authority_record()
{
	global $authority_record;

	if( ! $authority_record )
	{
		require_once dirname( __FILE__ ) .'/class-authority-posttype.php';
		$authority_record = new Authority_Posttype;
	}

	return $authority_record;
}

function authority_easyterms()
{
	global $authority_easyterms;

	if( ! $authority_easyterms )
	{
		require_once dirname( __FILE__ ) .'/class-authority-easyterms.php';
		$authority_easyterms = new Authority_EasyTerms;
	}

	return $authority_easyterms;
}

function new_authority_csv( $filename = FALSE , $columns = NULL , $delimiter = ',' , $enclosure = '"' )
{
	require_once dirname( __FILE__ ) . '/class-authority-csv.php';
	return new Authority_Csv( $filename , $columns , $delimiter , $enclosure );
}
