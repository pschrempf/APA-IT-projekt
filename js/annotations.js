jQuery(document).ready(function() {
	
	jQuery('.select-all').click(function() {
		var checkboxes = jQuery('input[type=checkbox]');
		for(var i=0; i < checkboxes.length; i++) {
			checkboxes[i].checked = this.checked;
		}
	});
	
	jQuery('#search').on('keydown', function(e) {
		var val;
		if (e.keyCode == 13 && '' != (val = jQuery('#search').val()) ) {
			var attribute_tag = 'search=';
			val = encodeURIComponent(val).replace(/'/g, "%27");
			if (window.location.search == '') {
				window.location.search = '?' + attribute_tag + val;
			} else if (window.location.search.includes(attribute_tag) ) {
				var url_parts = window.location.search.split(attribute_tag);
				var href = url_parts[0] + attribute_tag + val;
				var further_attr = url_parts[1].split('&');
				for (var i = 1; i < further_attr.length; i++) {
					href += '&' + further_attr[i];
				}
				window.location.href = href;
			} else {
				window.location.href = window.location + '&' + attribute_tag + val;
			}
		}
	});
	
	jQuery('#delete').click(function() {
		var checkboxes = jQuery('input[class=anno]:checked');
		if(checkboxes.length == 0) {
			alert(CONSTANTS.delete_error);
		} else if (confirm(CONSTANTS.delete_confirmation)) {	
			checkboxes = jQuery('input[class=anno]');
			var data = {
				'function': 'delete',
				'elements': []
			};
			
			for (var i = 0; i < checkboxes.length; i++) {
				if(checkboxes[i].checked) {
					var hash = checkboxes[i].value;
					jQuery('input[value="' + hash + '"]').parent().parent().addClass('delete');
					
					data.elements.push({ 'hash': hash });
				}
			}
			
			jQuery('body').ajaxStart(function() {
			    jQuery(this).css({'cursor': 'wait'});
			}).ajaxStop(function() {
			    jQuery(this).css({'cursor': 'default'});
			});
			
			jQuery.ajax({
				type: 'POST',
				url: CONSTANTS.annotate_db, 
				data: data,
				datatype: 'text',
				success: function( response ) {
					checkboxes = jQuery('input[class=anno]');
					for (var i = 0; i < checkboxes.length; i++) {
						if(checkboxes[i].checked) {
							var hash = checkboxes[i].value;
							jQuery('input[value="' + hash + '"]').parent().parent().hide();
							
							checkboxes[i].checked = false;
						}
					}
				}
			});
		}
	});
});