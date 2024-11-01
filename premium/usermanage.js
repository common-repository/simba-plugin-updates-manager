jQuery(function($) {
	
	var box_class = '';
	
	function standard_response_parse(response) {
		try {
			resp = JSON.parse(response);
			if (resp.success) {
				if (resp.entitlementsnow && box_class != '') {
					$('.'+box_class).replaceWith(resp.entitlementsnow);
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
	
	$('body').on('click', '.udmanager-addonstable .udmanager_entitlement_delete', function(e) {
		e.preventDefault();
		if (!confirm(updraftmanagerlionp.reallydelete)) return;
		var addonbox = $(this).parents('.udmanager-addonbox').first();
		var slug = $(addonbox).data('entitlementslug');
		var key = $(addonbox).data('addonkey');
		var entitlement = $(this).data('entitlementid');
		$(addonbox).block({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });

		var userid = updraftmanagerlionp.userid;
		box_class = 'udmanager_show_addons_'+userid+'_'+slug;
		
		$.post(updraftmanagerlionp.ajaxurl, {
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
		}).fail(function() {
			$(addonbox).unblock();
			alert('There was an error, and your request was not successful');
		});
	});

	$('body').on('click', '.udmanager-addonstable .udmanager_entitlement_reset', function(e) {
		e.preventDefault();

		if (!confirm(updraftmanagerlionp.reallyreset)) return;

		var addonbox = $(this).parents('.udmanager-addonbox').first();
		var slug = $(addonbox).data('entitlementslug');
		var key = $(addonbox).data('addonkey');
		var entitlement = $(this).data('entitlementid');
		var userid = $(this).data('userid');
		$(addonbox).block({ message: '<h2>'+updraftmanagerlionp.processing+'</h2>' });
		
		var owner_userid = updraftmanagerlionp.userid;
		box_class = 'udmanager_show_addons_'+owner_userid+'_'+slug;
		
		$.post(updraftmanagerlionp.ajaxurl, {
			action: 'udmanager_ajax',
			subaction: 'entitlement_reset',
			nonce: updraftmanagerlionp.ajaxnonce,
			owner_userid: owner_userid,
			userid: userid,
			slug: slug,
			key: key,
			entitlement: entitlement
		}, function(response) {
			$(addonbox).unblock();
			standard_response_parse(response);
		}).fail(function() {
			$(addonbox).unblock();
			alert('There was an error, and your request was not successful');
		});

	});

});
