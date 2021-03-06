var orderView = (function($) {
    var mod = {};
    mod.saveContactInfo = function()
    {
        var dstr = $('.contact-field').serialize();
        dstr += '&orderID='+$('#orderID').val();
        $.ajax({
            type: 'post',
            data: dstr,
            dataType: 'json',
            success: function(resp) {
                console.log(resp);
            }
        });
    };

    mod.saveItem = function()
    {
        var dstr = $(this).closest('tbody').find('.item-field').serialize();
        dstr += '&orderID='+$('#orderID').val();
        dstr += '&changed='+$(this).attr('name');
        var elem = $(this).closest('tbody');
        $.ajax({
            type: 'post',
            data: dstr,
            success: function(resp) {
                if (resp.regPrice) {
                    elem.find('input[name="srp"]').val(resp.regPrice);
                }
                if (resp.total) {
                    elem.find('input[name="actual"]').val(resp.total);
                }
                if (resp.discount) {
                    if (elem.find('.disc-percent').html() !== 'Sale') {
                        elem.find('.disc-percent').html(resp.discount + '%');
                    }
                }
            }
        });
    };

    mod.confirmC = function(oid,tid,label){
        if (window.confirm("Are you sure you want to close this order as "+label+"?")){
            $.ajax({
            url: 'ajax-calls.php',
            type: 'post',
            data: 'action=closeOrder&orderID='+oid+'&status='+tid,
            success: function(){
                window.location = $('#redirectURL').val();
            }
            });
        }
    };

    mod.memNumEntered = function(){
        var oid = $('#orderID').val();
        var cardno = $('#memNum').val();	
        $.ajax({
        type: 'get',
        data: 'customer=1&orderID='+oid+'&memNum='+cardno,
        dataType: 'json',
        success: function(resp){
            if (resp.customer) {
                $('#customerDiv').html(resp.customer);
                mod.AfterLoadCustomer();
            }
            if (resp.footer) {
                $('#footerDiv').html(resp.footer);
                $('#confirm-date').change(function(e) {
                    mod.saveConfirmDate(e.target.checked, $('#orderID').val());
                });
            }
        }
        });
    };

    mod.afterLoadCustomer = function() {
        $('.contact-field').change(mod.saveContactInfo);
        $('#memNum').change(mod.memNumEntered);
        $('#ctcselect').change(function() {
            mod.saveCtC($(this).val(), $('#orderID').val());
        });
        $('#s_personNum').change(function() {
            mod.savePN($('#orderID').val(), $(this).val());
        });
        $('.done-btn').click(function(e) {
            mod.validateAndHome();
            e.preventDefault();
            return false;
        });
        $('#orderStatus').change(function() {
            mod.updateStatus($('#orderID').val(), $(this).val());
        });
        $('#orderStore').change(function() {
            mod.updateStore($('#orderID').val(), $(this).val());
        });
        $('.print-cb').change(function() {
            mod.togglePrint($('#orderID').val());
        });
    };

    mod.searchWindow = function (){
        window.open('search.php','Search',
            'width=350,height=400,status=0,toolbar=0,scrollbars=1');
    };

    mod.afterLoadItems = function() {
        $('.item-field').change(mod.saveItem);
        if ($('#newqty').length) {
            $('#newqty').focus();	
            $('#itemDiv form').submit(function (e) {
                mod.newQty($(this).data('order'), $(this).data('trans'));
                e.preventDefault();
                return false;
            });
        } else if ($('#newdept').length) {
            $('#newdept').focus();	
            $('#itemDiv form').submit(function (e) {
                mod.newDept($(this).data('order'), $(this).data('trans'));
                e.preventDefault();
                return false;
            });
        } else {
            $('#itemDiv form').submit(function(e) {
                mod.addUPC();
                e.preventDefault();
                return false;
            });
        }
        $('.close-order-btn').click(function (e) {
            mod.confirmC($('#orderID').val(), $(this).data('close'), $(this).html());
            e.preventDefault();
            return false;
        });
        $('.btn-delete').click(function (e) {
            mod.deleteID($(this).data('order'), $(this).data('trans'));
            e.preventDefault();
            return false;
        });
        $('.itemChkO').change(function () {
            mod.toggleO($(this).data('order'), $(this).data('trans'));
        });
        $('.itemChkA').change(function () {
            mod.toggleA($(this).data('order'), $(this).data('trans'));
        });
        $('.btn-search').click(mod.searchWindow);
    };

    mod.addUPC = function()
    {
        var oid = $('#orderID').val();
        var cardno = $('#memNum').val();
        var upc = $('#newupc').val();
        var qty = $('#newcases').val();
        $.ajax({
        type: 'post',
        data: 'orderID='+oid+'&memNum='+cardno+'&upc='+upc+'&cases='+qty,
        success: function(resp){
            $('#itemDiv').html(resp);
            mod.afterLoadItems();
        }
        });
    };
    mod.deleteID = function(orderID,transID)
    {
        $.ajax({
        data: '_method=delete&orderID='+orderID+'&transID='+transID,
        success: function(resp){
            $('#itemDiv').html(resp);
            mod.afterLoadItems();
        }
        });
    };
    mod.saveCtC = function (val,oid){
        $.ajax({
        url: 'ajax-calls.php',
        type: 'post',
        data: 'action=saveCtC&orderID='+oid+'&val='+val,
        });
    };
    mod.newQty = function (oid,tid){
        var qty = $('#newqty').val();
        $.ajax({
        type: 'post',
        data: 'orderID='+oid+'&transID='+tid+'&qty='+qty,
        success: function(resp){
            $('#itemDiv').html(resp);
            mod.afterLoadItems();
        }
        });
    };
    mod.newDept = function (oid,tid){
        var d = $('#newdept').val();
        $.ajax({
        type: 'post',
        data: 'orderID='+oid+'&transID='+tid+'&dept='+d,
        success: function(resp){
            $('#itemDiv').html(resp);
            mod.afterLoadItems();
        }
        });
    };
    mod.savePN = function (oid,val){
        $.ajax({
        url: 'ajax-calls.php',
        type: 'post',
        data: 'action=savePN&val='+val+'&orderID='+oid,
        });
    };
    mod.saveConfirmDate = function (val,oid){
        if (val){
            $.ajax({
            url: 'ajax-calls.php',
            type: 'post',
            data: 'action=confirmOrder&orderID='+oid,
            success: function(resp){
                $('#confDateSpan').html('Confirmed '+resp);
            }
            });
        } else {
            $.ajax({
            url: 'ajax-calls.php',
            type: 'post',
            data: 'action=unconfirmOrder&orderID='+oid,
            success: function(){
                $('#confDateSpan').html('Not confirmed');
            }
            });
        }
    };
    mod.togglePrint = function (oid)
    {
        $.ajax({
        dataType: 'post',
        data: 'togglePrint=1&orderID='+oid,
        });
    };
    mod.toggleO = function (oid,tid)
    {
        $.ajax({
        dataType: 'post',
        data: 'toggleMemType=1&orderID='+oid+'&transID='+tid,
        });
    };
    mod.toggleA = function (oid,tid)
    {
        $.ajax({
        dataType: 'post',
        data: 'toggleStaff=1&orderID='+oid+'&transID='+tid,
        });
    };
    mod.doSplit = function (oid,tid){
        var dcheck=false;
        $('select.editDept').each(function(){
            if ($(this).val() === '0'){
                dcheck=true;
            }
        });

        if (dcheck){
            window.alert("Item(s) don't have a department set");
            return false;
        }

        $.ajax({
        url: 'ajax-calls.php',
        type: 'post',
        data: 'action=SplitOrder&orderID='+oid+'&transID='+tid,
        success: function(resp){
            $('#itemDiv').html(resp);
            mod.afterLoadItems();
        }
        });
    };
    mod.validateAndHome = function (){
        var dcheck=false;
        $('select.editDept').each(function(){
            if ($(this).val() === '0'){
                dcheck=true;
            }
        });

        if (dcheck){
            window.alert("Item(s) don't have a department");
            return false;
        }

        var CtC = $('#ctcselect').val();
        if (CtC === '2'){
            window.alert("Choose Call to Confirm option");
            return false;
        }

        var nD = $('#nDept').val();
        var nT = $('#nText').val();
        if (nT !== '' && nD === '0') {
            window.alert("Assign your notes to a department");
        } else {
            window.location = $('#redirectURL').val();
        }

        return false;
    };
    mod.updateStatus = function updateStatus(oid,val){
        $.ajax({
        url: 'ajax-calls.php',
        type: 'post',
        data: 'action=UpdateStatus&orderID='+oid+'&val='+val,
        success: function(resp){
            $('#statusdate'+oid).html(resp);	
        }
        });
    };
    mod.updateStore = function updateStore(oid, val)
    {
        $.ajax({
            url: 'ajax-calls.php',
            type: 'post',
            data: 'action=UpdateStore&orderID='+oid+'&val='+val
        });
    }

    return mod;

}(jQuery));

$(document).ready(function(){
	var initoid = $('#init_oid').val();
	$.ajax({
	type: 'get',
	data: 'customer=1&orderID='+initoid,
    dataType: 'json',
	success: function(resp){
        if (resp.customer) {
            $('#customerDiv').html(resp.customer);
            orderView.afterLoadCustomer();
        }
        if (resp.footer) {
            $('#footerDiv').html(resp.footer);
            $('#confirm-date').change(function(e) {
                orderView.saveConfirmDate(e.target.checked, $('#orderID').val());
            });
            $('.done-btn').click(function(e) {
                orderView.validateAndHome();
                e.preventDefault();
                return false;
            });
        }
		var oid = $('#orderID').val();
		$.ajax({
		type: 'get',
		data: 'items=1&orderID='+oid,
		success: function(resp){
			$('#itemDiv').html(resp);
            orderView.afterLoadItems();
		}
		});
	}
	});
});

$(window).unload(function() {
	$('#nText').change();
});

