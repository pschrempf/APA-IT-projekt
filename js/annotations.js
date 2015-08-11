jQuery(document).ready(function() {
	
	// select all checkboxes on page
	jQuery( '.select-all' ).click( function() {
		var checkboxes = jQuery( 'input[type=checkbox]' );
		for( var i = 0; i < checkboxes.length; i++ ) {
			checkboxes[i].checked = this.checked;
		}
	});
	
	// when a key is pressed in the search bar
	jQuery( '#search' ).on( 'keydown', function(e) {
		var val;
		
		// if enter was pressed get search value
		if ( e.keyCode == 13 && '' != ( val = jQuery( '#search' ).val() ) ) {
			var attribute_tag = 'search=';
			val = encodeURIComponent( val ).replace( /'/g, '%27' );
			
			// redirect to the correct page
			if ( window.location.search == '' ) {
				window.location.search = '?' + attribute_tag + val;
			} else if ( window.location.search.includes( attribute_tag ) ) {
				var url_parts = window.location.search.split( attribute_tag );
				var href = url_parts[0] + attribute_tag + val;
				var further_attr = url_parts[1].split( '&' );
				for ( var i = 1; i < further_attr.length; i++ ) {
					href += '&' + further_attr[i];
				}
				window.location.href = href;
			} else {
				window.location.href = window.location + '&' + attribute_tag + val;
			}
		}
	});
	
	// when a delete button is clicked
	jQuery( '#delete' ).click( function() {
		// get all checked checkboxes on page
		var checkboxes = jQuery( 'input[class=anno]:checked' );
		
		// make sure at least one checkbox is selected
		if ( checkboxes.length == 0 ) {
			alert( CONSTANTS.delete_error );
		} 
		// else check that the user would really like to delete
		else if ( confirm( CONSTANTS.delete_confirmation ) ) {	
			checkboxes = jQuery( 'input[class=anno]' );
			var data = {
				'function': 'delete',
				'elements': []
			};
			
			// add hash of annotation to be deleted to data
			for ( var i = 0; i < checkboxes.length; i++ ) {
				if ( checkboxes[i].checked ) {
					var hash = checkboxes[i].value;
					jQuery( 'input[value="' + hash + '"]' ).parent().parent().addClass( 'delete' );
					
					data.elements.push({
						'hash': hash 
					});
				}
			}
			
			jQuery( 'body' ).ajaxStart( function() {
			    jQuery( this ).css({ 'cursor': 'wait' });
			}).ajaxStop( function() {
			    jQuery( this ).css({ 'cursor': 'default' });
			});
			
			// carry out POST request to 'annotate-db.php'
			jQuery.ajax({
				type: 'POST',
				url: CONSTANTS.annotate_db, 
				data: data,
				datatype: 'text',
				success: function( response ) {
					
					// on success hide annotations from table
					checkboxes = jQuery( 'input[class=anno]' );
					for ( var i = 0; i < checkboxes.length; i++ ) {
						if ( checkboxes[i].checked ) {
							var hash = checkboxes[i].value;
							jQuery( 'input[value="' + hash + '"]' ).parent().parent().hide();
							
							checkboxes[i].checked = false;
						}
					}
				}
			});
		}
	});
});