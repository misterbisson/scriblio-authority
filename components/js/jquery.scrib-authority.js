/*!
 * ScribAuthority jQuery plugin
 *
 * USAGE:
 *
 * Initialization:
 * $('#element').ScribAuthority({ taxonomies: [ 'something', 'tag' ] });
 *
 * Adding default items:
 * $('#element').ScribAuthority( 'items', [
 *   {
 *     taxonomy: 'something',
 *     term: 'some-term',
 *     data: {
 *       term: 'something:some-term'
 *     }
 *   },
 *   ...
 * ] );
 *
 * Adding results:
 * $('#element').ScribAuthority( 'results', [
 *   {
 *     taxonomy: 'something',
 *     term: 'some-term',
 *     data: {
 *       term: 'something:some-term'
 *     }
 *   },
 *   ...
 * ] );
 *
 */
(function( $ ) {
	var defaults = {
		id: null,
		classes: null
	};

	var selector = 'scrib-authority-box';
	var options = {};
	var html = {};
	var selectors = {};
	var timeout_handler = null;

	var methods = {
		init: function( params ) {
			options = $.extend( defaults, params );

			// set up the html injection variables
			html = {
				wrapper : '<div class="' + selector + '" />',
				item    : '<li class="' + selector + '-item" />',
				items   : '<ul class="' + selector + '-items"></ul>',
				entry   : '<input type="text" class="' + selector + '-entry" />'
			};

			// initilaize the common selectors that we'll be using
			selectors.wrapper   = '.' + selector;
			selectors.category  = selectors.wrapper + '-result-category';
			selectors.category_results  = selectors.wrapper + '-result-category-results';
			selectors.category_custom  = selectors.wrapper + '-result-category-custom';
			selectors.entry     = selectors.wrapper + '-entry';
			selectors.item      = selectors.wrapper + '-item';
			selectors.items     = selectors.wrapper + '-items';
			selectors.newitem   = selectors.wrapper + '-new';
			selectors.noresults = selectors.wrapper + '-no-results';
			selectors.results   = selectors.wrapper + '-results';
			selectors.close     = selectors.item + ' .close';

			var $results = $('<ul class="' + selector +'-results"/>');
			$results.append( $('<li class="' + selector + '-result-category ' + selector + '-result-category-results"><h4>Results</h4><ul></ul></li>') );
			$results.append( $('<li class="' + selector + '-result-category ' + selector + '-result-category-custom"><h4>Custom</h4><ul></ul></li>') );
			$results.find('.' + selector + '-result-category-results ul').append('<li class="' + selector + '-no-results">No results!</li>');

			var $entry_container = $('<div class="' + selector + '-entry-container"/>');
			$entry_container.append( html.entry );
			$entry_container.append( $results );

			return this.each( function() {
				var $orig;
				var $root;

				// wrap and hide the original bound element
				$orig = $( this );
				$orig.wrap( html.wrapper );
				$orig.hide();

				// identify the root element for the Authority UI
				$root = $orig.closest( selectors.wrapper );

				// archive off the ID of the original bound element
				$root.data('target', $orig.attr('id'));

				// if there was an id attribute passed along in the options, set the id element of the root
				if( null !== options.id ) {
					$root.attr('id', options.id);
					options.id = null;
				}//end if

				// if there were some classes passed in the options, add those to the root
				if( null !== options.classes ) {
					if( options.classes instanceof Array ) {
						$.each( options.classes, function( index, value ) {
							$root.addClass( value );
						});
					} else {
						$root.addClass( options.classes );
					}//end else

					options.classes = null;
				}//end if

				// add the items container
				$root.append( html.items );

				// add the entry/results container
				$root.append( $entry_container );
				$root.append('<div class="' + selector + '-clearfix"/>');

				methods.taxonomies( $(this), options.taxonomies );

				// click event: result item
				$root.on( 'click.scrib-authority-box', selectors.results + ' ' + selectors.item, function( e ) {
					e.preventDefault();

					methods.select_item( $(this), $root );
					methods.update_target( $root );
				});

				// click event: root element
				$root.on( 'click.scrib-authority-box', function( e ) {
					// if the root element is clicked, focus the entry
					$(this).find( selectors.entry ).focus();
				});

				// click event: base item
				$root.on( 'click.scrib-authority-box', selectors.item, function( e ) {
					// all we want to do is stop propagation so the entry isn't auto-focused
					e.stopPropagation();
				});

				// click event: item close
				$root.on( 'click.scrib-authority-box', selectors.close, function( e ) {
					// an item is being x-ed out.  remove it
					e.stopPropagation();

					methods.remove_item( $(this).closest( selectors.item ), $root );
					methods.update_target( $root );
				});

				// keydown event: entry field
				$root.on( 'keydown.scrib-authority-box', selectors.entry, function( e ) {
					// the keys that are handled in here: navigation and selection
					var code = (e.keyCode ? e.keyCode : e.which);

					if( 40 === code ) {
						// if DOWN arrow is pressed
						var $focused = $root.find( selectors.results + ' .focus' );

						if( ! $focused.length ) {
							$root.find( selectors.results + ' ' + selectors.item + ':first' ).addClass('focus');
						} else {
							if ( 0 == $focused.nextAll( selectors.item ).length ) {
								$focused.closest( selectors.category ).nextAll( selectors.category ).find( selectors.item + ':first' ).addClass('focus');
							} else {
								$focused.nextAll( selectors.item ).first().addClass('focus');
							}//end else

							$focused.removeClass('focus');
						}//end else
					} else if( 38 === code ) {
						// if UP arrow is pressed
						var $focused = $root.find( selectors.results + ' .focus' );

						if( ! $focused.length ) {
							$root.find( selectors.results + ' ' + selectors.item + ':last' ).addClass('focus');
						} else {
							if ( 0 == $focused.prevAll( selectors.item ).length ) {
								$focused.closest( selectors.category ).prevAll( selectors.category ).find( selectors.item + ':first' ).addClass('focus');
							} else {
								$focused.prevAll( selectors.item ).first().addClass('focus');
							}//end else

							$focused.removeClass('focus');
						}//end else
					} else if( 13 === code ) {
						// if ENTER is pressed
						e.preventDefault();
						$root.find( selectors.results + ' .focus' ).removeClass('focus').click();
					} else if( 27 === code ) {
						// if ESC is pressed
						$root.find( selectors.results + ' .focus' ).removeClass('focus');
						$root.find( selectors.entry ).val('');
						methods.hide_results( $root );
					}//end else
				});

				// keyup event: entry field
				$root.on( 'keyup.scrib-authority-box', selectors.entry, function( e ) {
					// the keys that are handled in here: backspace, delete, and regular characters
					var code = (e.keyCode ? e.keyCode : e.which);

					if ( 48 <= code || 8 === code || 46 === code ) {
						// if a valid char is pressed
						$root.find( selectors.newitem ).find('.term').html( $(this).val() );
						if( '' === $.trim( $(this).val() ) ) {
							methods.hide_results( $root );
						} else {
							if ( timeout_handler ) {
								window.clearTimeout( timeout_handler );
							}//end if

							timeout_handler = window.setTimeout( function() {
								methods.search( $root, $root.find( selectors.entry ) );
							}, 300 );
						}//end else
					}//end if
				});
			});
		},
		/**
		 * This method generates a data string based on the currently selected items
		 *
		 * @param string which The data element to retrieve
		 */
		data_string: function( which ) {
			var $el = methods.root( $(this) );
			var serialized = $el.ScribAuthority('serialize');

			var terms = [];

			$.each( serialized, function( index, value ) {
				terms.push( value.taxonomy.name + ':' + value.term );
			});

			return terms.join(',');
		},
		/**
		 * generate item HTML based on an object.
		 *
		 * @param object data Object representing an item
		 */
		generate_item: function( data ) {
			var $item = $( html.item );

			// let's store the object that is used to generate this item.
			$item.data( 'origin-data', data );

			// loop over the properties in the item and add them to the HTML
			$.each( data, function( key, data_value ) {
				// the only exception are the data elements.  Add them to the item's data storage
				if( 'data' == key ) {
					$.each( data_value, function( data_key, key_value ) {
						$item.data( data_key, key_value );
					});
				} else if ( 'taxonomy' == key ) {
					var $taxonomy = $('<span class="' + key + '">' + data_value.labels.singular_name + '</span>');
					$taxonomy.data( 'taxonomy', data_value );

					$item.append( $taxonomy );
				} else {
					$item.append( $('<span class="' + key + '" />').html( data_value ) );
				}//end if
			});

			// gotta add the close box!
			$item.append( '<span class="close">x</span>' );

			return $item;
		},
		/**
		 * hide the results box
		 *
		 * @param jQueryObject $root Root element for this UI widget
		 */
		hide_results: function( $root ) {
			$root.find( selectors.results + '.show' ).removeClass('show');
		},
		/**
		 * add an item to either the results or the items HTML area
		 *
		 * @param jQueryObject $el Element for finding the root element
		 * @param String container Area the elements will be added to ( results or items )
		 * @param Object data Item definition object
		 */
		inject_item: function( $el, container, data ) {
			var $el = methods.root( $el );
			var $item = methods.generate_item( data );

			if ( 0 !== $el.find( selectors[container] + ' ' + selectors.category ).length ) {
				$el.find( selectors[container] + ' ' + selectors.category + '-results ul' ).append( $item );
			} else {
				$el.find( selectors[container] ).append( $item );
			}//end else
		},
		/**
		 * inject an item into the 'items' HTML area
		 *
		 * @param Object data Item definition object
		 */
		item: function( data ) {
			methods.inject_item( this, 'items', data );
		},
		/**
		 * populate the 'items' HTML area
		 *
		 * @param Array data Array of item definition objects to insert
		 */
		items: function( data ) {
			return this.each( function() {
				var $el = $(this);
				var $root = methods.root( $(this) );
				$root.data( 'items', data );

				$.each( data, function( i, value ) {
					$el.ScribAuthority('item', value);
				});
			});
		},
		/**
		 * Remove an item from the 'items' HTML area
		 *
		 * @param jQueryObject $item Item to remove
		 * @param jQueryObject $root Root html element for authority UI
		 */
		remove_item: function( $item, $root ) {
			var items = $root.data( 'items' );
			var new_items = [];
			var origin = $item.data( 'origin-data' );

			$.each( items, function( i, value ) {
				var temp_combo = value.taxonomy.name + ':' + value.term;
				var temp_origin_combo = origin.taxonomy.name + ':' + origin.term;
				if ( temp_combo != temp_origin_combo ) {
					new_items.push( value );
				}//end if
			});

			$root.data( 'items', new_items );

			$item.remove();
		},
		/**
		 * inject an item into the 'results' HTML area
		 *
		 * @param Object data Item definition object
		 */
		result: function( data ) {
			methods.inject_item( this, 'results', data );
		},
		/**
		 * populate the 'results' HTML area
		 *
		 * @param Array data Array of item definition objects to insert
		 */
		results: function( data ) {
			return this.each( function() {
				var $el = $(this);
				var $root = methods.root( $el );
				var items = $el.data('items');

				if ( ! items ) {
					items = [];
				}//end if

				$el.find( selectors.results + ' ' + selectors.item + ':not(' + selectors.newitem + ')' ).remove();

				if ( data.length > 0 ) {
					$.each( data, function( i, value ) {
						// if the results item DOES NOT exist in the set of elements already selected,
						//   add it to the result area
						if ( 0 === $.grep( items, function( element, index ) { return element.data.term === value.data.term; }).length ) {
							$el.ScribAuthority('result', value);
						}//end if
					});
				}//end if
			});
		},
		/**
		 * locate the root Authority UI element
		 *
		 * @param jQueryObject $el Child element of root used to find root.
		 */
		root: function( $el ) {
			if( ! $el.hasClass( selector ) ) {
				$el = $el.closest( selectors.wrapper );
			}//end if

			return $el;
		},
		search: function( $root, $entry ) {
			var params = {
				action: 'authority_admin_suggest',
				s: $entry.val()
			};

			var xhr = $.get(
				ajaxurl,
				params
			);

			$.when( xhr ).done( function( data ) {
				if ( typeof data != 'undefined' ) {
					$root.ScribAuthority('results', data);
					methods.show_results( $root );
				}//end if
			});
		},
		/**
		 * Select an item from the 'results' HTML area and move it to the 'items area'
		 *
		 * @param jQueryObject $item Selected item
		 * @param jQueryObject $root Root Authority UI element
		 */
		select_item: function( $item, $root ) {
			// get the cached items object from the root element
			var items = $root.data('items');

			// add the selected item's object data into the items object
			$root.data( 'items', items );

			if( $item.is( selectors.newitem ) ) {
				var $newitem = $item.clone();
				$newitem.data('origin-data', {
					taxonomy: $item.find('.taxonomy').data('taxonomy'),
					term: $item.find('.term').html()
				});

				$newitem.find('.taxonomy').data('taxonomy', $item.find('.taxonomy').data('taxonomy'));

				$newitem.removeClass( selectors.newitem.substring( 1 ) ).appendTo( $root.find( selectors.items ) );
				items.push( $newitem.data( 'origin-data' ) );
			} else {
				$root.find( selectors.items ).append( $item );
				items.push( $item.data( 'origin-data' ) );
			}//end else
			$root.find( selectors.entry ).focus();

			if( $root.find( selectors.items ).find( selectors.item ).length === 0 ) {
				$root.find( selectors.noitems ).show();
			}//end if
		},
		/**
		 * serialize the selected items into an array
		 */
		serialize: function() {
			var $el = methods.root( $(this) );
			var data = [];
			$el.find( selectors.items + ' ' + selectors.item ).each( function() {
				var $term = $(this);

				var row = {
					taxonomy: $term.find('.taxonomy').data('taxonomy'),
					term: $term.find('.term').html()
				};

				data.push( row );
			});

			return data;
		},
		/**
		 * display the results drop-down auto-completer
		 *
		 * @param jQueryObject $root Root Authority UI HTML element
		 */
		show_results: function( $root ) {
			var $results = $root.find( selectors.results );

			if( $results.find( selectors.item ).length > 0 ) {
				$results.find( selectors.noresults ).hide();
			}//end if

			$results.addClass('show');
		},
		taxonomies: function( $el, taxonomies ) {
			var $root = methods.root( $el );
			options.taxonomies = taxonomies;

			var $categories = $root.find( selectors.category + '-custom' ).find('ul');

			if ( options.taxonomies ) {
				$.each( options.taxonomies, function( i, value ) {
					var $item = $('<li class="' + selector + '-item ' + selector + '-new"/>');
					var $taxonomy = $('<span class="taxonomy">' + value.labels.singular_name + '</span>');
					$taxonomy.data('taxonomy', value);
					$item.append( $taxonomy );
					$item.append( '<span class="term"></span><span class="close">x</span>' );
					$categories.append( $item );
				});
			}//end else
	  },
		/**
		 * update the target UI element (textarea or input, typically) with the serialized/converted
		 * selected items
		 *
		 * @param jQueryObject $root Root Authority UI element
		 */
		update_target: function( $root ) {
			var $target = $root.find( '#' + $root.data('target') );
			$target.val( $target.ScribAuthority('data_string', 'term') );
		}
	};

	$.fn.ScribAuthority = function( method ) {
    // Method calling logic
    if ( methods[method] ) {
      return methods[ method ].apply( this, Array.prototype.slice.call( arguments, 1 ));
    } else if ( typeof method === 'object' || ! method ) {
      return methods.init.apply( this, arguments );
    } else {
      $.error( 'Method ' +  method + ' does not exist on jQuery.ScribAuthority' );
    }
	};

})( jQuery );
