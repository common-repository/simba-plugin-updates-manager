jQuery(function($) {
	
	var dragtablerowhelper = function(e, ui) {
		ui.children().each(function() {
			$(this).width($(this).width());
		});
		return ui;
	};

	if (updraftmanagerlion.managerurl.length > 0 && 'http' == updraftmanagerlion.managerurl.substring(0,4)) {
		$.get(updraftmanagerlion.managerurl, function(data, resp) {
			console.log(data);
			console.log(resp);
			if ('success' == resp && data.indexOf("<html>") != -1) {
				$('div.wrap').first().prepend('<div class="error">'+updraftmanagerlion.httpnotblocked+'</div>');
			}
		});
	}
	
	function debugmode_setui(on_page_load) {
		if ($('#udmanager_debugmode').is(':checked')) {
// 			if (on_page_load) {
				$('#updraftmanager_form .show_in_debug_mode_only').show();
// 			} else {
// 				$('#updraftmanager_form .show_in_debug_mode_only').show();
// 			}
		} else {
// 			if (on_page_load) {
				$('#updraftmanager_form .show_in_debug_mode_only').hide();
// 			} else {
// 				$('#updraftmanager_form .show_in_debug_mode_only').hide();
// 			}
		}
	}
	
	$('#udmanager_debugmode').change(function() {
		debugmode_setui();
	});
	debugmode_setui(true);
	
	$('form.updraftmanager_ruletable tbody').sortable({
		helper: dragtablerowhelper,
		stop: function(event, ui) {
			var orderstring = '';
			$('form.updraftmanager_ruletable tbody tr').each(function(ind, obj) {
				var ruleno = $(obj).children('.ruleno').html();
				orderstring += (orderstring == '') ? ruleno : ','+ruleno;
			});
			$('form.updraftmanager_ruletable').block({ 
				message: '<strong>'+updraftmanagerlion.processing+'</strong>', 
				css: { border: '1px solid #000' } 
			}); 
			$.post(ajaxurl, {
				action: 'udmanager_ajax',
				subaction: 'reorderrules',
				nonce: updraftmanagerlion.ajaxnonce,
				slug: updraftmanagerlion.slug,
				order: orderstring
			}, function(response) {
				try {
					resp = JSON.parse(response);
					if (resp.r != 'ok') {
						window.location = '?page=updraftmanager&action=managezips&slug='+updraftmanagerlion.slug+'&nonce='+updraftmanagerlion.ajaxnonce;
						return;
					}
					// Need to replace the table in order to have all the IDs in the right places
					window.location = '?page=updraftmanager&action=managezips&slug='+updraftmanagerlion.slug+'&nonce='+updraftmanagerlion.ajaxnonce;
				} catch(err) {
					// The table is wrong; reload the page
					console.log(err);
					window.location = '?page=updraftmanager&action=managezips&slug='+updraftmanagerlion.slug+'&nonce='+updraftmanagerlion.ajaxnonce;
				}
			});
		}
	}).disableSelection();
	
	$('#updraftmanager_form').on('change', '.ud_rule_type', function() {
		var val = $(this).val();
		var parent = $(this).parents('.ud_rule_row');
		if ('always' == val) {
			$(parent).find('.ud_rule_relationship, .ud_rule_value').hide();
		} else {
			$(parent).find('.ud_rule_relationship, .ud_rule_value').show();
			
			if ('siteurl' == val || 'username' == val || 'random_percent' == val) {
				var $rule_relationship = $(parent).find('.ud_rule_relationship');
				if ('random_percent' == val) {
					$rule_relationship.val('lt');
				} else {
					$rule_relationship.val('eq');
				}
				if ('random_percent' == val) {
					$rule_relationship.hide();
				} else {
					$rule_relationship.show();
				}
			}
		}
	});
	
	$('#updraftmanager_form').on('change', '#udm_newform_freeplugin', function() {
		var ckd = $(this).is(':checked');
		if (ckd) {
			$('#updraftmanager_form .udm_newform_addonsrow').slideUp();
		} else {
			$('#updraftmanager_form .udm_newform_addonsrow').slideDown();
		}
	});
	
	$('#updraftmanager_form').on('change', '.ud_rule_relationship', function() {
		var parent = $(this).parents('.ud_rule_row');
		var val = $(parent).children('.ud_rule_type').first().val();
		if ('siteurl' == val || 'username' == val) {
			$(this).val('eq');
		} else if ('random_percent' == val) {
			$(this).val('lt');
		}
	});
	
	$('.udmplugin_delete').click(function(e) {
		if (!confirm(updraftmanagerlion.areyousureplugin)) {
			e.preventDefault();
		}
	});
	
	$('.udmzip_delete').click(function(e) {
		if (!confirm(updraftmanagerlion.areyousurezip)) {
			e.preventDefault();
		}
	});
	
	$('.udmrule_delete').click(function(e) {
		if (!confirm(updraftmanagerlion.areyousurerule)) {
			e.preventDefault();
		}
	});
	
	$('#updraftmanager_newrule').click(function(e) {
		e.preventDefault();
		updraftmanager_newline();
	});
	
	$('#updraftmanager_rules').on('click', '.ud_deleterow', function(e) {
		e.preventDefault();
		var prow = $(this).parent('.ud_rule_row');
		$(prow).slideUp().delay(400).remove();
	});
	
});

jQuery.fn.animateBorder = function(highlightColor, duration) {
	var highlightBg = highlightColor || "#FFFF9C";
	var animateMs = duration || 1500;
	var originalBg = this.css("borderColor");
	this.stop().css("border-color", highlightBg).animate({borderColor: originalBg}, animateMs);
};

function updraftmanager_rule_submit() {
	var retval = true;
	jQuery("#updraftmanager_form .ud_rule_relationship").each(function(ind, obj) {
		var parent = jQuery(obj).parents('.ud_rule_row');
		var criteria = jQuery(parent).children('.ud_rule_type').first();
		if ('always' != jQuery(criteria).val() && 'range' == jQuery(obj).val()) {
			var valbox = jQuery(parent).children('.ud_rule_value').first();
			var val = jQuery(valbox).val();
			if (val.indexOf(',') == -1 || '' == val) {
				retval = false;
				jQuery(valbox).animateBorder('red', 3000);
			}
		} else if ('always' != jQuery(criteria).val()) {
			var valbox = jQuery(parent).children('.ud_rule_value').first();
			var val = jQuery(valbox).val();
			if ('' == val) {
				retval = false;
				jQuery(valbox).animateBorder('red', 3000);
			}
		}
	});
	return retval;
}

var updraftmanager_lineno = 0;

function updraftmanager_newline(lineno, criteria, condition, value) {
	
	var lineno = updraftmanager_lineno;
	updraftmanager_lineno++;
	
	if (typeof criteria == 'undefined') criteria = 'always';
	if ('random_percent' == criteria) {
		condition = 'lt';
		if (value < 0) value = 0;
		if (value > 100) value = 100;
	}
	
	var newhtml = '<div id="ud_rule_'+lineno+'" class="ud_rule_row">\
	\
	<label for="ud_rule_type['+lineno+']">'+updraftmanagerlion.rule+'</label>\
	<select class="ud_rule_type" name="ud_rule_type['+lineno+']" id="ud_rule_'+lineno+'">\
	<option value="always" '+(('always' === criteria) ? 'selected="selected" ' : '')+'title="'+updraftmanagerlion.applyalways+'">'+updraftmanagerlion.alwaysmatch+'</option>\
	<option value="installed" '+(('installed' === criteria) ? 'selected="selected" ' : '')+' title="'+updraftmanagerlion.version+'">'+updraftmanagerlion.installedversion+'</option>\
	<option value="wp" '+(('wp' === criteria) ? 'selected="selected" ' : '')+' title="'+updraftmanagerlion.ifwp+'">'+updraftmanagerlion.wpver+'</option>\
	<option value="php" '+(('php' === criteria) ? 'selected="selected" ' : '')+' title="'+updraftmanagerlion.ifphp+'">'+updraftmanagerlion.phpver+'</option>\
	<option value="random_percent" '+(('random_percent' === criteria) ? 'selected="selected" ' : '')+' title="'+updraftmanagerlion.if_random+'">'+updraftmanagerlion.random_percent+'</option>';
	
	if (0 == updraftmanager_freeversion) {
		newhtml +='<option value="username" '+(('username' === criteria) ? 'selected="selected" ' : '')+' title="'+updraftmanagerlion.ifusername+'">'+updraftmanagerlion.username+'</option>';
	}
	
	newhtml += '<option value="siteurl" '+(('siteurl' === criteria) ? 'selected="selected" ' : '')+' title="'+updraftmanagerlion.ifsiteurl+'">'+updraftmanagerlion.siteurl+'</option>\
	</select>';

	newhtml += '<select ';
	if ('always' === criteria || 'random_percent' == criteria) newhtml += 'style="display:none;" ';
	newhtml += 'class="ud_rule_relationship" name="relationship['+lineno+']" id="ud_rule_relationship'+lineno+'">\
	<option value="eq" '+(('eq' == condition) ? 'selected="selected"' : '')+'>'+updraftmanagerlion.equals+'</option>\
	<option value="lt" '+(('lt' == condition) ? 'selected="selected"' : '')+'>'+updraftmanagerlion.lessthan+'</option>\
	<option value="gt" '+(('gt' == condition) ? 'selected="selected"' : '')+'>'+updraftmanagerlion.greaterthan+'</option>\
	<option value="range" '+(('range' == condition) ? 'selected="selected"' : '')+'>'+updraftmanagerlion.range+'</option>\
	</select>\
	<input type="text" ';

	if ('always' === criteria) newhtml += 'style="display:none;" ';
	
	newhtml += 'class="ud_rule_value" name="ud_rule_value['+lineno+']" id="ud_rule_value_'+lineno+'" value="'+((typeof value != 'undefined') ? value : '')+'" title="'+updraftmanagerlion.rangeexplain+'">\
	\
	<span title="'+updraftmanagerlion.delete+'" class="ud_deleterow">X</span></div>\
	</div>';
	
	jQuery('#updraftmanager_rules').append(newhtml);
}
