<?php
/*
Plugin Name: Scriblio Term Authority
Plugin URI: http://about.scriblio.net/
Version: a1
Author: Casey Bisson
Author URI: http://maisonbisson.com/blog/
Contributors: borkweb, abackstrom
License: GPL2
*/

/*  Copyright 20012 Casey Bisson  (email : casey dot bisson at gmail dot com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


// include required components
require_once dirname( __FILE__ ) . '/components/class-scriblio-authority-suggest.php';
require_once dirname( __FILE__ ) . '/components/functions.php';

scriblio_authority_suggest();

// Configure the supported taxonomies for authority records
authority_record()->add_taxonomy( 'post_tag' );
authority_record()->add_taxonomy( 'category' );

// Configure the supported taxonomies for easy terms
authority_easyterms()->add_taxonomy( 'post_tag' );
authority_easyterms()->add_taxonomy( 'category' );
