jQuery(function($) {
	
	var udmanager_query_leaving = false;
	
	window.onbeforeunload = function(e) {
		if (udmanager_query_leaving) {
			var ask = updraftmanagerlionp.unsavedsettings;
			e.returnValue = ask;
			return ask;
		}
	}
	
	$("#updraftmanager_form.udm_renewalsettings input, #updraftmanager_form.udm_renewalsettings textarea, #updraftmanager_form.udm_renewalsettings select").change(function() {
		udmanager_query_leaving = true;
	});
	$("#updraftmanager_saverenewalsettings").click(function() {
		udmanager_savesettings("savesettings");
	});
	
	function udmanager_savesettings() {
		udmanager_doformaction(updraftmanagerlionp.saving, 'saverenewalsettings');
	}

	$('#updraftmanager_sendtestemail_go').click(function() {
		udmanager_doformaction(updraftmanagerlionp.sendingtest, 'sendtestemail');
	});
	
	$('#udmanager_sendremindersnow_go').click(function() {
		udmanager_doformaction(updraftmanagerlionp.sendremindersnow, 'sendreminders');
	});
	
	function udmanager_get_serialized_formdata(subaction) {
		var formData = jQuery("#updraftmanager_form input, #updraftmanager_form select").serialize();
		var which_checkboxes = "#updraftmanager_form";
		
		// https://stackoverflow.com/questions/10147149/how-can-i-override-jquerys-serialize-to-include-unchecked-checkboxes

		// include unchecked checkboxes. use filter to only include unchecked boxes.
		jQuery.each(jQuery(which_checkboxes+" input[type=checkbox]")
		.filter(function(idx){
			return jQuery(this).prop("checked") === false
		}),
		function(idx, el){
			// attach matched element names to the formData with a chosen value.
			var emptyVal = "0";
			formData += "&" + jQuery(el).attr("name") + "=" + emptyVal;
		}
		);
		
		var renewal_email_contents = jQuery('#renewal_email_contents').val();
		try {
			var tinymce_email_contents = tinyMCE.activeEditor.getContent();
			renewal_email_contents = tinymce_email_contents;
		} catch (e) {
			console.log("Exception when calling tinyMCE.activeEditor.getContent");
			console.log(e);
		}
		formData += '&renewal_email_contents=' + encodeURIComponent(renewal_email_contents);
		
		if (subaction == 'sendtestemail') {
			formData += '&sendtestemail=' + encodeURIComponent(jQuery('#udmanager_sendtestemail').val());
		}

		return formData;
	}
	
	function udmanager_doformaction(blockmessage, subaction) {
		jQuery.blockUI({ message: "<h1>"+blockmessage+"</h1>" });
		
		var formData = udmanager_get_serialized_formdata(subaction);
		
		jQuery.post(ajaxurl, {
			action: 'udmanager_ajax',
			subaction: subaction,
			settings: formData,
			nonce: updraftmanagerlionp.ajaxnonce
		}, function(response) {
			try {
				resp = JSON.parse(response);
				if (resp.result == "ok") {
					if ('saverenewalsettings' == subaction) { udmanager_query_leaving = false; }
				} else if (resp.hasOwnProperty('message')) {
					console.log(resp.result);
					alert(resp.message);
				} else {
					alert(updraftmanagerlionp.response+" "+resp.result);
				}
			} catch(err) {
				alert(updraftmanagerlionp.response+" "+response);
				console.log(response);
				console.log(err);
			}
			jQuery.unblockUI();
		});
	}
	
	function standard_response_parse(response) {
		try {
			resp = JSON.parse(response);
			if (resp.success) {
				if (resp.entitlementsnow) {
					$('#updraftmanager_user_plugin_entitlements').replaceWith(resp.entitlementsnow);
					set_up_datepickers();
				}
			} else if (resp.hasOwnProperty('msg')) {
				alert(resp.msg);
			} else {
				alert(resp.success);
			}
		} catch(err) {
			console.log(err);
			console.log(response);
		}
	}
	
	$('.wrap').on('click',  '.udmanager_entitlement_add, .udmanager_entitlement_add_unlimited', function(e) {
		e.preventDefault();
		var addonbox = $(this).parents('.udmanager-addonbox').first();
		var slug = $(addonbox).data('entitlementslug');
		var pot_key = $(addonbox).data('addonkey');
		var howmany = prompt(updraftmanagerlionp.howmanymonthsnew, 12);
		if (!howmany) return;
		var unlimited = $(this).hasClass('udmanager_entitlement_add_unlimited') ? 1 : 0;
		var sdata = {
			action: 'udmanager_ajax',
			subaction: 'entitlement_add',
			nonce: updraftmanagerlionp.ajaxnonce,
			userid: updraftmanagerlionp.userid,
			slug: slug,
			unlimited: unlimited,
			howmany: howmany
		};
		if (typeof pot_key != 'undefined') {
			sdata.key = pot_key;
		}
		$(addonbox).block({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });
		$.post(ajaxurl, sdata, function(data, response) {
			$(addonbox).unblock();
			standard_response_parse(data);
		});
	});

	$('.wrap').on('click', '.udmanager_entitlement_reset', function(e) {
		e.preventDefault();
		if (!confirm(updraftmanagerlionp.reallyreset)) return;
		var addonbox = $(this).parents('.udmanager-addonbox').first();
		var slug = $(addonbox).data('entitlementslug');
		var key = $(addonbox).data('addonkey');
		var entitlement = $(this).data('entitlementid');
		var userid = $(this).data('userid');
		$(addonbox).block({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });
		$.post(ajaxurl, {
			action: 'udmanager_ajax',
			subaction: 'entitlement_reset',
			nonce: updraftmanagerlionp.ajaxnonce,
			userid: userid,
			slug: slug,
			key: key,
			entitlement: entitlement
		}, function(response) {
			$(addonbox).unblock();
			standard_response_parse(response);
		});
	});
	
	$('.wrap').on('click', '.udmanager_entitlement_delete', function(e) {
		e.preventDefault();
		if (!confirm(updraftmanagerlionp.reallydelete)) return;
		var addonbox = $(this).parents('.udmanager-addonbox').first();
		var slug = $(addonbox).data('entitlementslug');
		var key = $(addonbox).data('addonkey');
		var entitlement = $(this).data('entitlementid');
		$(addonbox).block({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });
		$.post(ajaxurl, {
			action: 'udmanager_ajax',
			subaction: 'entitlement_delete',
			nonce: updraftmanagerlionp.ajaxnonce,
			userid: updraftmanagerlionp.userid,
			slug: slug,
			key: key,
			entitlement: entitlement
		}, function(response) {
			$(addonbox).unblock();
			standard_response_parse(response);
		});
	});
	
	$('.wrap').on('click', '.udmplugin_expiry_reset', function() {
		var $date_input = $(this).siblings('.udmplugin_set_all_expiries');
		var slug = $date_input.data('slug');
		var date_chosen = $('#udmplugin_expiry_result_'+slug).val();

		if (date_chosen == '') {
			return;
		}
		
		if (!confirm(updraftmanagerlionp.reallyresetall)) return;
								
		$.blockUI({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });
		$.post(ajaxurl, {
			action: 'udmanager_ajax',
			subaction: 'entitlements_reset_all',
			nonce: updraftmanagerlionp.ajaxnonce,
			userid: updraftmanagerlionp.userid,
			slug: slug,
			date: date_chosen
		}, function(response) {
			$.unblockUI();
			standard_response_parse(response);
		});
		
	});

	function set_up_datepickers() {
		$( ".udmplugin_set_all_expiries" ).each(function(ind, item) {
			var slug = $(item).data('slug');
			$('#udmplugin_expiry_result_'+slug).datepicker({
				changeMonth: true,
				changeYear: true,
				dateFormat: 'yy-mm-dd'
			});
		});
	}
	
	set_up_datepickers();
	
	$('.wrap').on('click', '.udmanager-addonstable .udmplugin_set_all_expiries', function(e) {
		var slug = $(this).data('slug');
		var result = '#udmplugin_expiry_result_'+slug;
		e.preventDefault();
		$(result).datepicker('show');
	});

	$('.wrap').on('click', '.udmanager_entitlement_extend', function(e) {
		e.preventDefault();
		var howmany = prompt(updraftmanagerlionp.howmanymonths, 12);
		if (!howmany) return;
		var addonbox = $(this).parents('.udmanager-addonbox').first();
		var slug = $(addonbox).data('entitlementslug');
		var key = $(addonbox).data('addonkey');
		var entitlement = $(this).data('entitlementid');
		$(addonbox).block({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });
		$.post(ajaxurl, {
			action: 'udmanager_ajax',
			subaction: 'entitlement_extend',
			nonce: updraftmanagerlionp.ajaxnonce,
			userid: updraftmanagerlionp.userid,
			slug: slug,
			key: key,
			entitlement: entitlement,
			howmany: howmany
		}, function(response) {
			$(addonbox).unblock();
			standard_response_parse(response);
		});
	});
});
