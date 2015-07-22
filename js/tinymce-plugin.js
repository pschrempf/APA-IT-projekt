jQuery(document).ready(function(jQuery){
	
	tinymce.PluginManager.add('annotate', function(editor, url) {
		var wm = editor.windowManager;
		
		// Add annotate button
		editor.addButton('annotate', {
			title: 'Annotate',
			tooltip: 'Annotate',
			
			/* Either have text or image here */
			
			text: 'Annotate', 
			//image: url + '/../img/apa.jpg',
			
			//on click annotate if there is text in the editor
			onclick: function() {
				//get text from editor
				var content = editor.getContent();
				
				if ('' === content) {
					wm.alert('Please enter text to be annotated!');
				} else {
					//APA annotation service test URL
					var ps_annotate_url = 'http://apapses5.apa.at:7070/fliptest_tmp/cgi-bin/ps_annotate';
					
					/* get info from OPTIONS PAGE */
					
					var data = {
						contenttype: 'application/json',
						reqid: 'request',
						lang: 'GER',
						text: content,
						skip: 'GER:Austria_Presse_Agentur|GER:Deutsche_Presse-Agentur'
					};
					
					
					//POST request to ps_annotate server
					jQuery.ajax({
						type: 'POST',
						url: ps_annotate_url,
						crossDomain: true,
						dataType: 'json',
						data: data,
						error: handle_request_error,						
						success: function( response ) {
							response = cleanResponse(response);
							
							if (!response.hasOwnProperty('concepts')) {
								wm.alert("No annotations could be found!");
							} else {
								//opens selection form
								var selection_form = WORDPRESS.selection_form;
								var selection_form_id = 'selectionform';
								wm.open({
									url : selection_form,
									title: 'Annotation results',
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
									//add information to 'selection_form.html' from results
									var table_contents;
									response.concepts.forEach(function(element) {
										table_contents += generateRow(element);
									});
									jQuery(iframe_identifier).contents().find('table').append(table_contents);
									
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
										
										//create individual microdata for each selection
										selection.some(function(element) {
											element.phrase = findPhraseInContent(response.content, element.concept);
											if (element.phrase === false) {
												return false;
											}
											if( WORDPRESS.add_microdata ) {											
												var microdata = createMicrodata(element, url);
											} else {
												var microdata = createAnnotation( element );
											}
											
											var post_title = jQuery('#titlewrap input').val();
											var db_annotate_url = WORDPRESS.annotate_db;
											addAnnotationToDB(post_title, element, db_annotate_url);
											
											content = content.replace(
												new RegExp('([ ,.:\(_\-])(' + element.phrase + ')([ ,.:_\)\-])', 'g'), '$1' + microdata + "$3");
										}, this);
										
										//add article microdata
										//content = addArticleMicrodata(content);
										
										editor.setContent(content);
										
										wm.close();
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

	function createAnnotation( element ) {
		var name = cleanName(element.concept);
		var link = window.location.hostname + '/annotations?search=' + encode(name);
		
		return `<a href=${link}><span>${element.phrase}</span></a>`;
	}
	
	function createMicrodata( element, url ) {
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
		
		return `<a href=${link} itemscope itemtype=${schema}><span itemprop="name">${element.phrase}</span></a>`;
	}
	
	/**
	 * Encodes part of a URL including parentheses.
	 */
	function encode(url) {
		url = encodeURIComponent(url);
		url = url.replace(/\(/g, '%28').replace(/\)/g, '%29');
		return url;
	}
	
	function addArticleMicrodata(content) {
		var post_prologue = '<div itemscope="" itemtype="http://schema.org/Article"><div itemprop="articleBody"> ';
		var post_epilogue = '</div></div>';
		return post_prologue + content + post_epilogue;
	}
	
	/**
	 * Generates an individual table row depending on information
	 */
	function generateRow(element) {
		var table_row = `<tr class="annotation">`
			+ `\n\t<td class="input"><input type="checkbox" class="selector" name="checkbox"></td>`
			+ `\n\t<td><span title="${element.abstract}">${cleanName(element.concept)}</span></td>`
			+ `\n\t<td>${element.type}</td>`;
		
		//prefer companylogos over thumbimages
		if(typeof element.complogo !== 'undefined') {
			table_row += `\n\t<td class="image"><img src="${element.complogo}" alt=""/></td>`;
		} else if(typeof element.thumbimg !== 'undefined') {
			table_row += `\n\t<td class="image"><img src="${element.thumbimg}" alt=""/></td>`;
		} else {
			table_row += '\n\t<td></td>';
		}
		table_row += '\n</tr>';
		return table_row;
	}
	
	/**
	 * Removes unwanted dates and e-mail addresses from response.
	 */
	function cleanResponse(response) {
		var indices = [];
		var counter = 0;
		alert(
			'test: ' + WORDPRESS.annotate_db +
			'\nemail: ' + WORDPRESS.annotate_email.toString() +
			'\n date: ' + WORDPRESS.annotate_date.toString() + 
			'\n    url: ' + WORDPRESS.annotate_url.toString()
		);
		response.concepts.forEach(function(element) {
			if ((false == WORDPRESS.annotate_email && element.type == 'mailaddr' ) || 
				(false == WORDPRESS.annotate_date && element.type == 'date' ) || 
				(false == WORDPRESS.annotate_url && element.type == 'URL') ) {
				indices[indices.length] = counter;
			}
			counter++;
		});
		for (var index = indices.length-1; index >= 0; index--) {
			var element = indices[index];
			response.concepts.splice(element, 1);
		}
		if (response.concepts.length == 0) {
			delete response.concepts;
		}
		return response;
	}
	
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
	
	function beginsWith(haystack, needle) {
		var res = haystack.substr( 0, needle.length ) == needle;
		alert(res);
		return res;
	}
	
	function cleanName(name) {
		return name.substr(4, name.length).replace(new RegExp('_', 'gi'), ' ');
	}
	
	function addAnnotationToDB(post_title, element, url) {
		var data = {
			'function': 'add',
			'name': cleanName(element.concept),
			'title': post_title,
			'type': element.type
		};
		
		jQuery.ajax({
			type: 'POST',
			url: url,
			data: data,
			datatype: JSON,
			error: handle_request_error,
			success: function( response ) {
				//ignore
			}
		});
	}

});