/**
 * Heidelpay WPF Javascript
 */

function init()
{ 
    // The method “getElem” can be used to get an item on the page by its id
    getElem('id', 'contactBlock', 0).style.display="none";
    getElem('id', 'userInfoBlock', 0).style.display="none";
    getElem('id', 'addressBlock', 0).style.display="none";
    getElem('id', 'paymentSelection', 0).style.display="none";
    getElem('id', 'notMandatoryRow', 0).style.display="none";
}
