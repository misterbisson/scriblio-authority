/*!
 * ScribAuthority jQuery plugin
 * Author: Matthew Batchelder
 * Author URI: http://borkweb.com
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
		classes: null,
		custom_enabled: true,
		replace_field: true,
		labels: {
			results: 'Results'
		}
	};

	var selector = 'scrib-authority-box';
	var options = {};
	var html = {};
	var selectors = {};
	var timeout_handler = null;

	var methods = {
		init: function( params ) {

			if ( 'undefined' != typeof params && 'undefined' != typeof params.url ) {
				scrib_authority_suggest.url = params.url;
			}

			// fix the ajax url
			if ( 'https:' === window.location.protocol ) {
				scrib_authority_suggest.url = scrib_authority_suggest.url.replace( 'http:', 'https:' );
			} else {
				scrib_authority_suggest.url = scrib_authority_suggest.url.replace( 'https:', 'http:' );
			}//end if

			options = $.extend( defaults, params );

			var type = 'undefined' != typeof this.attr( 'type' ) ? this.attr( 'type' ) : 'text';

			// set up the html injection variables
			html = {
				wrapper : '<div class="' + selector + '" />',
				item    : '<li class="' + selector + '-item" />',
				items   : '<ul class="' + selector + '-items"></ul>',
				entry   : '<input type="' + type + '" class="' + selector + '-entry ' + selector + '-input" />'
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
			$results.append( $('<li class="' + selector + '-result-category ' + selector + '-result-category-results"><h4>' + options.labels.results + '</h4><ul></ul></li>') );

			if ( options.custom_enabled ) {
				$results.append( $('<li class="' + selector + '-result-category ' + selector + '-result-category-custom"><h4>Custom</h4><ul></ul></li>') );
			}//end if

			$results.find('.' + selector + '-result-category-results ul').append('<li class="' + selector + '-no-results">No terms were found matching your search.</li>');

			var $entry_container = $('<div class="' + selector + '-entry-container"/>');

			$entry_container.append( html.entry );

			if ( ! options.replace_field ) {
				$entry_container.find( selectors.wrapper + '-input' ).removeClass( selectors.entry.substr( 1 ) );
			}//end if

			$entry_container.append( $results );

			return this.each( function() {
				var $orig;
				var $root;
				var $entry;

				// wrap and hide the original bound element
				$orig = $( this );
				$orig.wrap( html.wrapper );

				if ( options.replace_field ) {
					$orig.hide();
				} else {
					$orig.addClass( selectors.entry.substr( 1 ) );
				}//end else

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

				//set top to the inverse of search-box margin to ensure it snugs up to the search box
				$entry_container.css('top', function() {
					var margin = $( selectors.entry ).css( 'margin-bottom' ).replace("px", "");

					return parseInt( margin, 10 ) * -1;
				});

				$root.append('<div class="' + selector + '-clearfix"/>');

				if ( options.replace_field ) {
					$entry = $root.find( selectors.entry );
					for ( var attr, i = 0, attrs = $orig.get(0).attributes, length = attrs.length; i < length; i++ ) {
						attr = attrs.item( i );

						if ( 'name' !== attr.nodeName && 'class' !== attr.nodeName && 'id' !== attr.nodeName && 'type' !== attr.nodeName && 'style' !== attr.nodeName ) {
							$entry.attr( attr.nodeName, attr.nodeValue );
						}//end if
					}//end for
				}//end if

				methods.taxonomies( $(this), options.taxonomies );

				// click event: result item
				$root.on( 'click.scrib-authority-box MSPointerDown.scrib-authority-box', selectors.results + ' ' + selectors.item, function( e ) {
					e.preventDefault();

					methods.select_item( $(this), $root );
					methods.update_target( $root );
				});

				$root.$current_touch_item = null;
				$root.current_touch_y_pos = null;

				$root.on( 'touchstart.scrib-authority-box', selectors.results + ' ' + selectors.item, function( e ) {
					$root.$current_touch_item = $( this );
					$root.current_touch_y_pos = e.changedTouches[0].pageY;
				});

				$root.on( 'touchend.scrib-authority-box', selectors.results + ' ' + selectors.item, function( e ) {
					e.preventDefault();

					// if there isn't a touch start item, bail
					if ( ! $root.$current_touch_item ) {
						$root.$current_touch_item = null;
						$root.current_touch_y_pos = null;
						return;
					}//end if

					var original_item = $root.$current_touch_item.find( '.taxonomy' ).html();
					original_item = original_item + ':' + $root.$current_touch_item.find( '.term' ).html();

					var current_item = $( this ).find( '.taxonomy' ).html();
					current_item = current_item + ':' + $ele.find( '.term' ).html();

					var $ele = $( this );

					// if the end item is not the start item, bail
					if ( original_item !== current_item ) {
						$root.$current_touch_item = null;
						$root.current_touch_y_pos = null;
						return;
					}//end if

					// if there are no changedTouches, bail
					if ( 'undefined' === typeof e.changedTouches || 0 === e.changedTouches.length ) {
						$root.$current_touch_item = null;
						$root.current_touch_y_pos = null;
						return;
					}//end if

					//we need a bit of a delay here and below to avoid race conditions that prevent these from firing correctly
					var lastTest = window.setTimeout(
						function(){

						// if the changed touches moved more than 10 pixels in any direction, bail (we're probably scrolling)
						if ( ( $root.current_touch_y_pos + 10 ) < e.changedTouches[0].pageY || ( $root.current_touch_y_pos - 10 ) > e.changeTouches[0].pageY ) {
							$root.$current_touch_item = null;
							$root.current_touch_y_pos = null;
							return;
						}//end if
					}, 200);

					//we need a bit of a delay here and above to avoid race conditions that prevent these from firing correctly
					var doUpdates = window.setTimeout(
						function(){

						$root.$current_touch_item = null;
						$root.current_touch_y_pos = null;

						methods.select_item( $ele, $root );
						methods.update_target( $root );
					}, 500);
				});

				// click event: root element
				$root.on( 'click.scrib-authority-box touchstart.scrib-authority-box MSPointerDown.scrib-authority-box', function( e ) {
					var $entry = $( this ).find( selectors.entry );
					var $results = $entry.closest( '.scrib-authority-box' ).find( '.scrib-authority-box-results.has-results' );

					// if the root element is clicked, focus the entry
					$entry.focus();

					// when focusing, if the input box already has content in it that returned results, show them
					if ( $entry.val() && $results.length ) {
						$results.addClass( 'show' );
					}//end if
				});

				// click event: handles dismissing the results box if clicking off of the box or search
				$( document ).on( 'click.scrib-authority-box-cancel touchstart.scrib-authority-box-cancel MSPointerDown.scrib-authority-box-cancel', function( e ) {
					var $el = $( e.target );

					if ( $el.is( '.scrib-authority-box' ) || 0 !== $el.closest( '.scrib-authority-box').length ) {
						return;
					}//end if

					methods.hide_results( $root );
				});

				// click event: base item
				$root.on( 'click.scrib-authority-box touchstart.scrib-authority-box MSPointerDown.scrib-authority-box', selectors.item, function( e ) {
					// all we want to do is stop propagation so the entry isn't auto-focused
					e.stopPropagation();
				});

				// click event: item close
				$root.on( 'click.scrib-authority-box touchstart.scrib-authority-box MSPointerDown.scrib-authority-box', selectors.close, function( e ) {
					// an item is being x-ed out.  remove it
					e.stopPropagation();

					methods.remove_item( $(this).closest( selectors.item ), $root );
					methods.update_target( $root );
				});

				$root.on( 'keydown.scrib-authority-box-down', selectors.entry, function( e ) {
					// the keys that are handled in here: navigation and selection
					var code = (e.keyCode ? e.keyCode : e.which);

					if ( 40 === code ) {
						// if DOWN arrow is pressed
						var $focused = methods.focused_result( $root );

						if ( ! $focused.length ) {
							$root.find( selectors.results + ' ' + selectors.item + ':first' ).addClass('focus');
						} else {
							if ( 0 === $focused.nextAll( selectors.item ).length ) {
								$focused.closest( selectors.category ).nextAll( selectors.category ).find( selectors.item + ':first' ).addClass('focus');
							} else {
								$focused.nextAll( selectors.item ).first().addClass('focus');
							}//end else

							$focused.removeClass('focus');
						}//end else
					}//end if
				});

				$root.on( 'keydown.scrib-authority-box-up', selectors.entry, function( e ) {
					// the keys that are handled in here: navigation and selection
					var code = (e.keyCode ? e.keyCode : e.which);

					if ( 38 === code ) {
						// if UP arrow is pressed
						var $focused = methods.focused_result( $root );

						if ( ! $focused.length ) {
							$root.find( selectors.results + ' ' + selectors.item + ':last' ).addClass('focus');
						} else {
							if ( 0 === $focused.prevAll( selectors.item ).length ) {
								$focused.closest( selectors.category ).prevAll( selectors.category ).find( selectors.item + ':first' ).addClass('focus');
							} else {
								$focused.prevAll( selectors.item ).first().addClass('focus');
							}//end else

							$focused.removeClass('focus');
						}//end else
					}//end if
				});

				// keydown event: entry field
				$root.on( 'keydown.scrib-authority-box-enter', selectors.entry, function( e ) {
					// the keys that are handled in here: navigation and selection
					var code = (e.keyCode ? e.keyCode : e.which);

					if( 13 === code ) {
						var $focused = methods.focused_result( $root );

						if ( $focused.length && methods.is_results_visible( $root ) ) {
							// if ENTER is pressed
							e.preventDefault();
							$focused.removeClass('focus').click();
							$root.find( selectors.entry ).val('');
							methods.hide_results( $root );
						} else if ( $.trim( $(this).val() ).length ){
							var val = $.trim( $(this).val() );
							$orig.val( val );

							$(this).trigger( 'scriblio-authority-enter', {
								value: val
							});
						}//end else
					}//end if
				});

				// keydown event: entry field
				$root.on( 'keydown.scrib-authority-box-esc', selectors.entry, function( e ) {
					// the keys that are handled in here: navigation and selection
					var code = (e.keyCode ? e.keyCode : e.which);

					if ( 27 === code ) {
						var $focused = methods.focused_result( $root );

						// if ESC is pressed
						$focused.removeClass('focus');
						methods.hide_results( $root );
					}//end if
				});

				// keyup event: entry field
				$root.on( 'keyup.scrib-authority-box', selectors.entry, function( e ) {
					// the keys that are handled in here: backspace, delete, and regular characters
					var code = (e.keyCode ? e.keyCode : e.which);
					var val = $(this).val();

					// disallow < and >
					if ( 188 === code || 190 === code ) {
						$(this).val( val.replace( '>', '' ).replace( '<', '' ) );
						return false;
					}//end if

					if ( 48 <= code || 8 === code || 46 === code ) {
						// if a valid char is pressed
						$root.find( selectors.newitem ).find('.term').html( val );
						if( 0 === $.trim( $(this).val() ).length ) {
							$root.closest( 'form' ).removeClass( 'has-text' );
							$root.find( selectors.results ).removeClass( 'has-results no-results' );
							methods.hide_results( $root );
						} else {
							$root.closest( 'form' ).addClass( 'has-text' );
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
		 * returns a focused result
		 *
		 * @param jQueryObject $root Root element for this UI widget
		 */
		focused_result: function( $root ) {
			return $root.find( selectors.results + ' .focus' );
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
				if ( ! data_value ) {
					return;
				}//end if

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
					$item.prepend( $('<span class="' + key + '" />').html( data_value ) );
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
			$el = methods.root( $el );
			var $item = methods.generate_item( data );

			if ( 0 !== $el.find( selectors[container] + ' ' + selectors.category ).length ) {
				$el.find( selectors[container] + ' ' + selectors.category + '-results ul' ).append( $item );
			} else {
				$el.find( selectors[container] ).append( $item );
			}//end else
		},
		/**
		 * determines if results are visible
		 *
		 * @param jQueryObject $root Root element for this UI widget
		 */
		is_results_visible: function( $root ) {
			return $root.find( selectors.results ).hasClass('show');
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
			var items = $root.data( 'items' ) || [];
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
				s: $.trim( $entry.val() ),
				threshold: scrib_authority_suggest.threshold
			};

			if ( 0 === params.s.length ) {
				return;
			}//end if

			var url = scrib_authority_suggest.url;

			// we need to handle both pretty and admin-ajax URLs - so ? may or may not be present
			if ( -1 !== url.indexOf( '?' ) ) {
				url += '&callback=?';
			} else {
				url += '?callback=?';
			}//end else

			var xhr = $.getJSON( url, params );

			xhr.done( function( data ) {
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
			var items = $root.data('items') || [];

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

			// advertise that an item has been selected
			$item.trigger( 'scriblio-authority-item-selected', { item: $item });
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

			if( $results.find( '.scrib-authority-box-result-category-results ' + selectors.item ).length > 0 ) {
				$results.addClass( 'has-results' ).removeClass( 'no-results' );
				$results.find( selectors.noresults ).hide();
			} else {
				$results.removeClass( 'has-results' ).addClass( 'no-results' );
				$results.find( selectors.noresults ).show();
			}//end else

			if ( 0 !== $.trim( $root.find( selectors.entry ).val() ).length ) {
				$results.addClass('show');
			}//end if
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
					$item.prepend( '<span class="term"></span>' );
					$item.append( '<span class="close">x</span>' );
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
