jQuery(document).ready(function(jQuery){
	
	tinymce.PluginManager.add('annotate', function(editor, url) {
		var wm = editor.windowManager;
		
		// Add annotate button to editor
		editor.addButton('annotate', {
			title: 'Annotate',
			tooltip: CONSTANTS.button_tooltip,
			text: CONSTANTS.button_text, 
			
			//on click annotate if there is text in the editor
			onclick: function() {
				//get content from editor
				var content = editor.getContent();
				var cleanContent = editor.getContent({format:'text'});
				
				//if there is no content alert user
				if ('' === content) {
					wm.alert(CONSTANTS.no_text_alert);
				} 
				//else annotate content
				else {
					//set data for POST request
					var data = {
						contenttype: 'application/json',
						reqid: 'request',
						lang: SETTINGS.lang,
						text: cleanContent,
						skip: SETTINGS.skip
					};
					
					//POST request to ps_annotate server
					jQuery.ajax({
						type: 'POST',
						url: CONSTANTS.ps_annotate_url,
						crossDomain: true,
						dataType: 'json',
						data: data,
						error: handle_request_error,		
										
						//on success work with response
						success: function( response ) {
							response = cleanResponse(response);
							
							//if no annotations could be found alert user
							if (!response.hasOwnProperty('concepts')) {
								wm.alert(CONSTANTS.no_annotations_alert);
							} 
							//else open selection form
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
								
								//wait until iframe is loaded
								var iframe_identifier = '#' + selection_form_id + ' iframe';
								jQuery(iframe_identifier).load(function() {
									
									//add information to table in 'selection_form.html' from results
									var table_contents = 
										'<tr class="title">' + 
											'<th class="input"><input type="checkbox" class="select-all"></th>' + 
											'<th>' + CONSTANTS.results_name + '</th>' + 
											'<th>' + CONSTANTS.results_type + '</th>' +
											'<th></th>' +
										'</tr>';
									
									response.concepts.forEach(function(element) {
										table_contents += generateRow(element);
									});
									jQuery(iframe_identifier).contents().find('table').append(table_contents);
									jQuery(iframe_identifier).contents().find('table').after(
										'<br>' + 
										'<input id="button" type="submit" value="' + CONSTANTS.button_text + '" form="target">'
									);
									
									jQuery(iframe_identifier).contents().find('input[class=select-all]').click(function() {
										var checkboxes = jQuery(iframe_identifier).contents().find('input[name=checkbox]');
										for(var i=0; i < checkboxes.length; i++) {
											checkboxes[i].checked = this.checked;
										}
									});
									
									//on submit, enter selections to content
									jQuery(iframe_identifier).contents().find('form').submit(function( event ) {
										var selection = [];
										
										//add selected annotations to selection								
										var checkboxes = jQuery(iframe_identifier).contents().find('input[class=selector]');
										for (var i = 0; i < checkboxes.length; i++) {
											if(checkboxes[i].checked) {
												selection[selection.length] = response.concepts[i];
											}
										};
																			
										//create database entry for each selected annotation and edit content as settings require
										selection.some(function(element) {
											var post_title = jQuery('#titlewrap input').val();

											//define data for POST request
											var data = {
												'function': 'add',
												'name': cleanName(element.concept),
												'title': post_title,
												'type': element.type
											};
											
											//POST request to add annotation to database
											jQuery.ajax({
												type: 'POST',
												url: CONSTANTS.annotate_db,
												data: data,
												datatype: JSON,
												success: function(response) {
													//after the last element has been annotated alert user
													if(element === selection[selection.length-1]) {
														wm.alert(CONSTANTS.success);
													}
												}
											});
											
											//if add_links setting is activated add link
											if( SETTINGS.add_links ) {
												element.phrase = findPhraseInContent(response.content, element.concept);
												if (element.phrase === false) {
													return false;
												}
												
												//get microdata to replace phrase with
												var microdata;
												if( SETTINGS.add_microdata ) {											
													microdata = createMicrodataAnnotation(element, url);
												} else {
													microdata = createAnnotation( element );
												}
												
												//replace all occurences of the phrase with annotation
												content = content.replace(
													new RegExp('([ ,.:_\-])(' + element.phrase + ')([ ,.:_\-])', 'g'), '$1' + microdata + "$3");
											}
											
										}, this);
										
										editor.setContent(content);
										
										wm.close(selection_window);
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
	 */
	function findPhraseInContent(content, conceptToBeFound) {		
		var phrase = false;
		for (var i = 0; i < content.length; i++) {
			var element = content[i];
			if (element.hasOwnProperty('content')) {
				phrase = findPhraseInContent(element.content, conceptToBeFound);
				if (false !== phrase) {
					return phrase;
				}
			} else {
				for (var j = 0; j < element.concepts.length; j++) {
					var el = element.concepts[j];
					if (el.concept === conceptToBeFound) {
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
	 */
	function createAnnotation( element ) {
		var name = cleanName(element.concept);
		var link = window.location.hostname + '/annotations?search=' + encode(name);
		
		return '<annotation id="' + name + '"><a href=' + link + '><span>' + element.phrase + '</span></a></annotation>';
	}
	
	/**
	 * Creates an annotations with 'schema.org' microdata.
	 */
	function createMicrodataAnnotation( element, url ) {
		var name = cleanName(element.concept);
		var link = window.location.hostname + '/annotations?search=' + encode(name);
		var schema;
		
		if(element.type == 'location') {
			schema = 'http://schema.org/Place';
		} else if(element.type == 'person') {
			schema = 'http://schema.org/Person';
		} else if(element.type == 'organization') {
			schema = 'http://schema.org/Organization';
		} else {
			schema = 'http://schema.org/Thing';
		}
		
		return '<annotation id="' + name + '"><a href=' + link + ' itemscope itemtype=' + schema + '>'
			+ '<span itemprop="name">' + element.phrase + '</span></a></annotation>';
	}
	
	/**
	 * Encodes part of a URL including parentheses.
	 */
	function encode(url) {
		url = encodeURIComponent(url);
		url = url.replace(/\(/g, '%28').replace(/\)/g, '%29').replace(/'/g, "%27");
		return url;
	}
	
	/**
	 * Generates an individual table row depending on information
	 */
	function generateRow(element) {
		var table_row = '<tr class="annotation">'
			+ '\n\t<td class="input"><input type="checkbox" class="selector" name="checkbox"></td>'
			+ '\n\t<td><span title="' + element.abstract+'">' + cleanName(element.concept) + '</span></td>'
			+ '\n\t<td>' + element.type + '</td>';
		
		//prefer company logos over thumbimages
		if(typeof element.complogo !== 'undefined') {
			table_row += '\n\t<td class="image"><img src="' + element.complogo + '" alt=""/></td>';
		} else if(typeof element.thumbimg !== 'undefined') {
			table_row += '\n\t<td class="image"><img src="' + element.thumbimg + '" alt=""/></td>';
		} else {
			table_row += '\n\t<td></td>';
		}
		table_row += '\n</tr>';
		return table_row;
	}
	
	/**
	 * Removes unwanted annotations from response according to plugin settings.
	 */
	function cleanResponse(response) {
		var indices = [];
		var counter = 0;
		
		if( undefined === response.concepts ) {
			return response;
		}
		
		response.concepts.forEach(function(element) {
			if ((false == SETTINGS.annotate_email && element.type == 'mailaddr' ) || 
				(false == SETTINGS.annotate_date && element.type == 'date' ) || 
				(false == SETTINGS.annotate_url && element.type == 'URL') ) {
				indices[indices.length] = counter;
			}
			counter++;
		});
		
		//remove unwanted indices
		for (var index = indices.length-1; index >= 0; index--) {
			var element = indices[index];
			response.concepts.splice(element, 1);
		}
		
		//check if there are no annotations left in array
		if (response.concepts.length == 0) {
			delete response.concepts;
		}
		return response;
	}
	
	/**
	 * Reports various errors from post requests. 
	 */
	function handle_request_error(jqxhr, exception) {
		if (jqxhr.status === 0) {
			alert('Not connected.\nVerify Network.');
		} else if (jqxhr.status == 404) {
			alert('Requested page not found.');
		} else if (jqxhr.status == 500) {
			alert('Internal Server Error.');
		} else if (exception === 'parsererror') {
			alert('Requested JSON parse failed.');
		} else if (exception === 'timeout') {
			alert('Time out error.');
		} else if (exception === 'abort') {
			alert('Ajax request aborted.');
		} else {
			alert('Uncaught Error.\n' + jqxhr.responseText);
		}
	}
	
	/**
	 * Removes the language tag from the name of an annotation.
	 */
	function cleanName(name) {
		return name.substr(4, name.length).replace(new RegExp('_', 'gi'), ' ');
	}
	
	/**
	 * Sends a POST request to the 'annotate_db.php' file to add an entry to the database.
	 */
	function addAnnotationToDB(post_title, element) {
		var data = {
			'function': 'add',
			'name': cleanName(element.concept),
			'title': post_title,
			'type': element.type
		};
		jQuery.ajax({
			type: 'POST',
			url: CONSTANTS.annotate_db,
			data: data,
			datatype: JSON
		});
	}
});