jQuery( document ).ready( function( jQuery ){
	
	tinymce.PluginManager.add( 'annotate', function( editor, url ) {
		var wm = editor.windowManager;
		
		// add annotate button to editor
		editor.addButton( 'annotate', {
			title: 'Annotate',
			tooltip: CONSTANTS.button_tooltip,
			text: CONSTANTS.button_text, 
			
			// on click annotate if there is text in the editor
			onclick: function() {
				//get content from editor
				var content = editor.getContent();
				var cleanContent = editor.getContent({
					format: 'text'
				});
				
				// if there is no content alert user
				if ( '' === content ) {
					wm.alert( CONSTANTS.no_text_alert );
				} 
				// else annotate content
				else {
					// set data for POST request
					var data = {
						contenttype: 'application/json',
						reqid: 'request',
						lang: SETTINGS.lang,
						text: cleanContent,
						skip: SETTINGS.skip
					};
					
					// POST request to ps_annotate server
					jQuery.ajax({
						type: 'POST',
						url: CONSTANTS.ps_annotate_url,
						crossDomain: true,
						dataType: 'json',
						data: data,
						error: handle_request_error,		
										
						// on success work with response
						success: function( response ) {
							response = cleanResponse( response );
							
							// if no annotations could be found alert user
							if ( ! response.hasOwnProperty( 'concepts' )) {
								wm.alert( CONSTANTS.no_annotations_alert );
							} 
							// else open selection form
							else {
								var selection_form_id = 'selectionform';
								var selection_window = wm.open({
									url : CONSTANTS.selection_form,
									title: CONSTANTS.results_title,
									width : 900,
									height : 700,
									id: selection_form_id,
									resizable: true,
									maximizable: true
								}, {
									custom_param : 1
								});
								
								// wait until iframe is loaded
								var iframe_identifier = '#' + selection_form_id + ' iframe';
								jQuery( iframe_identifier ).load( function() {
									
									// create table contents from elements in response
									var table_contents = 
										'<tr class="title">' + 
											'<th class="input"><input type="checkbox" class="select-all"></th>' + 
											'<th>' + CONSTANTS.results_name + '</th>' + 
											'<th>' + CONSTANTS.results_type + '</th>' +
											'<th></th>' +
										'</tr>';
									
									response.concepts.forEach( function( element ) {
										table_contents += generateRow( element );
									});
									
									// add information to table in 'selection_form.html' from results
									jQuery( iframe_identifier ).contents().find( 'table' ).append( table_contents );
									
									// add button to page
									jQuery( iframe_identifier ).contents().find( 'table' ).after(
										'<br>' + 
										'<input id="button" class="custom_button" type="submit" value="' + CONSTANTS.button_text + '" form="target">'
									);
									
									// add 'select all' functionality
									jQuery( iframe_identifier ).contents().find( 'input[class=select-all]' ).click( function() {
										var checkboxes = jQuery( iframe_identifier ).contents().find( 'input[name=checkbox]' );
										for ( var i=0; i < checkboxes.length; i++ ) {
											checkboxes[i].checked = this.checked;
										}
									});
									
									// set cursor wheel for ajax request
									jQuery( 'body' ).ajaxStart( function() {
									    jQuery( this ).css({ 'cursor' : 'wait' });
									}).ajaxStop( function() {
									    jQuery( this ).css({ 'cursor' : 'default' });
									});
									
									// on submit, enter selections to content
									jQuery( iframe_identifier ).contents().find( 'form' ).submit( function( event ) {
										var selection = [];
										
										// add selected annotations to selection array
										var checkboxes = jQuery( iframe_identifier ).contents().find( 'input[class=selector]' );
										for ( var i = 0; i < checkboxes.length; i++ ) {
											if ( checkboxes[i].checked ) {
												selection[selection.length] = response.concepts[i];
											}
										};
										
										var data = {
											'function': 'add',
											'_ajax_nonce': SECURITY.nonce,
											'elements': []
										};
										
										// create database entry for each selected annotation and edit content as settings require
										selection.some( function( element ) {
											var post_title = jQuery( '#titlewrap input' ).val();
											element.hash = response.lang + '_' + element.hash;
											
											var img = '';
											if ( 'undefined' !== typeof element.complogo ) {
												img = element.complogo;
											} else if ( 'undefined' !== typeof element.thumbimg ) {
												img = element.thumbimg;
											}
																																
											// define element data for POST request
											data.elements.push({
												'title': post_title,
												'hash': element.hash,
												'name': cleanName(element.concept),
												'type': element.type,
												'link': element.link,
												'description': element.abstract,
												'image': img
											});
											
											
											if ( SETTINGS.add_links || SETTINGS.add_microdata ) {
												element.phrase = findPhraseInContent( response.content, element.concept );
												if ( false === element.phrase ) {
													return false;
												}
												
												// get microdata to replace phrase with according to SETTINGS
												var microdata;
												if ( SETTINGS.add_links && SETTINGS.add_microdata ) {
													microdata = createLinkMicrodataAnnotation( element );
												} else if ( SETTINGS.add_links ) {
													microdata = createLinkAnnotation( element );
												} else if ( SETTINGS.add_microdata ) {
													microdata = createMicrodataAnnotation( element );
												}
												
												// replace all occurences of the phrase with annotation
												content = content.replace(
													new RegExp( '([ ,.:_\-])(' + element.phrase + ')([ ,.:_\-])', 'g' ), '$1' + microdata + "$3" );
											}
											
										}, this );
										
										// POST request to add annotation to database
										jQuery.ajax({
											type: 'POST',
											url: CONSTANTS.annotate_db,
											data: data,
											datatype: JSON,
											success: function( response ) {
												// after the last element has been annotated alert user
												wm.alert( CONSTANTS.success );
											}
										});
										
										editor.setContent( content );
										
										wm.close( selection_window );
									});
								});
							}
						}
					});
				}
			}
		});	
	});
	
	/**
	 * Finds the phrase belonging to the concept.
	 * 
	 * @param {object} content 
	 * @param {string} conceptToBeFound
	 * @return {string} Phrase that was found or false if nothing was found
	 */
	function findPhraseInContent( content, conceptToBeFound ) {		
		var phrase = false;
		for ( var i = 0; i < content.length; i++ ) {
			var element = content[i];
			if ( element.hasOwnProperty( 'content' ) ) {
				phrase = findPhraseInContent( element.content, conceptToBeFound );
				if ( false !== phrase ) {
					return phrase;
				}
			} else {
				for ( var j = 0; j < element.concepts.length; j++ ) {
					var el = element.concepts[j];
					if ( el.concept === conceptToBeFound ) {
						phrase = element.phrase;
						return phrase;
					}
				}
			}
		}
		
		return false;
	}

	/**
	 * Creates an annotation with a link to its annotation page.
	 * 
	 * @param {object} element The element to be annotated.
	 * @return {string} The html to represent the annotation.
	 */
	function createLinkAnnotation( element ) {
		var name = cleanName( element.concept );
		var link = CONSTANTS.site_url + '/annotations/' + encode( name );
		
		return '<annotation id="' + element.hash + '">' 
			+ '<a href=' + link + '>' + element.phrase + '</a>'
			+ '</annotation>';
	}
	
	/**
	 * Creates an annotation with 'schema.org' microdata and a link.
	 * 
	 * @param {object} element The element to be annotated.
	 * @param {string} url 
	 * @return {string} The html to represent the annotation.
	 */
	function createLinkMicrodataAnnotation( element, url ) {
		var name = cleanName( element.concept );
		var link = CONSTANTS.site_url + '/annotations/' + encode( name );
		
		return '<annotation id="' + element.hash + '" itemscope itemtype="' + getSchema( element.type ) + '">'
			+ '<link itemprop="url" href="' + element.link + '" />'
			+ '<a href="' + link + '">'
			+ '<span itemprop="name">' + element.phrase + '</span>'
			+ '</a></annotation>';
	}
	
	/**
	 * Creates an annotation with 'schema.org' microdata only.
	 * 
	 * @param {object} element The element to be annotated.
	 * @param {string} url 
	 * @return {string} The html to represent the annotation.
	 */
	function createMicrodataAnnotation( element, url ) {
		return '<annotation id="' + element.hash + '" itemscope itemtype="' + getSchema( element.type ) + '">'
			+ '<link itemprop="url" href="' + element.link + '" />'
			+ '<span itemprop="name">' + element.phrase + '</span>'
			+ '</annotation>';
	}
	
	/**
	 * Creates a 'schema.org' schema according to the element type.
	 * 
	 * @param {string} elementType The type of element that is to be matched.
	 * @return {string} 'Schema.org' schema according to type.
	 */
	function getSchema( elementType ) {
		var schema = 'http://schema.org/';
		
		if ( 'location' == elementType ) {
			schema += 'Place';
		} else if ( 'person' == elementType ) {
			schema += 'Person';
		} else if ( 'organization' == elementType ) {
			schema += 'Organization';
		} else {
			schema += 'Thing';
		}
		
		return schema;
	} 
	
	/**
	 * Encodes part of a URL.
	 * 
	 * @param {string} url URL to be encoded.
	 * @return {string} Encoded URL.
	 */
	function encode( url ) {
		url = url.replace( / /g, '' );
		url = encodeURIComponent( url );
		return url;
	}
	
	/**
	 * Generates an individual table row depending on information.
	 * 
	 * @param {object} element The element holding the necessary information for the table row.
	 * @return {string} String representing the html for the table row.
	 */
	function generateRow( element ) {
		var table_row = '<tr class="annotation">'
			+ '\n\t<td class="input"><input type="checkbox" class="selector" name="checkbox"></td>'
			+ '\n\t<td><span title="' + element.abstract+'">' + cleanName( element.concept ) + '</span></td>'
			+ '\n\t<td>' + element.type + '</td>';
		
		// prefer company logos over thumbimages
		if ( 'undefined' !== typeof element.complogo ) {
			table_row += '\n\t<td class="image"><img src="' + element.complogo + '" alt=""/></td>';
		} else if ( 'undefined' !== typeof element.thumbimg ) {
			table_row += '\n\t<td class="image"><img src="' + element.thumbimg + '" alt=""/></td>';
		} else {
			table_row += '\n\t<td></td>';
		}
		table_row += '\n</tr>';
		return table_row;
	}
	
	/**
	 * Removes unwanted annotations from response.
	 * 
	 * @param {object} response The server response to be cleaned.
	 * @return {object} Cleaned response object.
	 */
	function cleanResponse( response ) {
		var indices = [];
		var counter = 0;
		
		if( undefined === response.concepts ) {
			return response;
		}
		
		response.concepts.forEach( function( element ) {
			if ( 'mailaddr' == element.type || 'date' == element.type || 'URL' == element.type ) {
				indices[indices.length] = counter;
			}
			counter++;
		});
		
		// remove unwanted indices
		for ( var index = indices.length-1; index >= 0; index-- ) {
			var element = indices[index];
			response.concepts.splice( element, 1 );
		}
		
		// check if there are no annotations left in array
		if ( 0 == response.concepts.length ) {
			delete response.concepts;
		}
		return response;
	}
	
	/**
	 * Reports various errors from post requests. 
	 * 
	 * @param {object} jqxhr
	 * @param {string} exception
	 */
	function handle_request_error( jqxhr, exception ) {
		if ( 0 === jqxhr.status ) {
			alert( 'Not connected.\nVerify Network.' );
		} else if ( 404 == jqxhr.status ) {
			alert( 'Requested page not found.' );
		} else if ( 500 == jqxhr.status ) {
			alert( 'Internal Server Error.' );
		} else if ( 'parsererror' === exception ) {
			alert( 'Requested JSON parse failed.' );
		} else if ( 'timeout' === exception ) {
			alert( 'Time out error.' );
		} else if ( 'abort' === exception ) {
			alert( 'Ajax request aborted.' );
		} else {
			alert( 'Uncaught Error.\n' + jqxhr.responseText );
		}
	}
	
	/**
	 * Removes the language tag and underscores from the name of an annotation.
	 * 
	 * @param {string} name Name to be cleaned.
	 */
	function cleanName( name ) {
		return name.substr( 4, name.length ).replace( new RegExp( '_', 'gi' ), ' ' );
	}
});