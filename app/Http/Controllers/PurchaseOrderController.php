<?php
/**
 * Created by PhpStorm.
 * User: Sugito
 * Date: 9/10/2016
 * Time: 2:17 PM
 */

namespace App\Http\Controllers;

use App\Model\Bank;
use App\Model\CashPayment;
use App\Model\Item;
use App\Model\Lookup;
use App\Model\Payment;
use App\Model\Product;
use App\Model\ProductUnit;
use App\Model\Supplier;
use App\Model\TransferPayment;
use App\Model\Warehouse;
use App\Model\PurchaseOrder;
use App\Model\VendorTrucking;

use App\Services\PurchaseOrderService;
use Auth;
use Illuminate\Http\Request;
use App\Util\POCodeGenerator;
use Illuminate\Support\Facades\Log;

class PurchaseOrderController extends Controller
{
    private $purchaseOrderService;

    public function __construct(PurchaseOrderService $purchaseOrderService)
    {
        $this->purchaseOrderService = $purchaseOrderService;
        $this->middleware('auth');
    }

    public function create()
    {
        Log::info('[PurchaseOrderController@create] ');

        $supplierDDL = Supplier::with('profiles.phoneNumbers.provider', 'bankAccounts.bank', 'products.productUnits.unit', 'products.type')->get();
        $warehouseDDL = Warehouse::all(['id', 'name']);
        $vendorTruckingDDL = VendorTrucking::all(['id', 'name']);
        $poTypeDDL = Lookup::where('category', '=', 'POTYPE')->get(['description', 'code']);
        $supplierTypeDDL = Lookup::where('category', '=', 'SUPPLIERTYPE')->get(['description', 'code']);
        $poCode = POCodeGenerator::generatePOCode();
        $poStatusDraft = Lookup::where('category', '=', 'POSTATUS')->get(['description', 'code'])->where('code', '=',
            'POSTATUS.D');

        return view('purchase_order.create', compact(
            'supplierDDL',
            'warehouseDDL',
            'vendorTruckingDDL',
            'supplierTypeDDL',
            'poTypeDDL',
            'unitDDL',
            'poStatusDraft',
            'poCode'));
    }

    public function store(Request $request)
    {
        Log::info('[PurchaseOrderController@store]');

        $this->validate($request, [
            'code' => 'required|string|max:255',
            'po_type' => 'required|string|max:255',
            'po_created' => 'required|string|max:255',
            'shipping_date' => 'required|string|max:255',
            'supplier_type' => 'required|string|max:255',
        ]);

        $createdPO = $this->purchaseOrderService->createPO($request);

        if (!empty($request->input('submitcreate'))) {
            return redirect()->action('PurchaseOrderController@create');
        } else {
            return redirect(route('db'));
        }
    }

    public function index()
    {
        Log::info('[PurchaseOrderController@index]');

        $purchaseOrders = PurchaseOrder::with('supplier')->whereIn('status', ['POSTATUS.WA', 'POSTATUS.WP'])->get();
        $poStatusDDL = Lookup::where('category', '=', 'POSTATUS')->get()->pluck('description', 'code');

        return view('purchase_order.index', compact('purchaseOrders', 'poStatusDDL'));
    }

    public function revise($id)
    {
        Log::info('[PurchaseOrderController@revise]');

        $currentPo = PurchaseOrder::with('items.product.productUnits.unit', 'supplier.profiles.phoneNumbers.provider',
            'supplier.bankAccounts.bank', 'supplier.products.productUnits.unit', 'supplier.products.type', 'vendorTrucking', 'warehouse')->find($id);
        $warehouseDDL = Warehouse::all(['id', 'name']);
        $vendorTruckingDDL = VendorTrucking::all(['id', 'name']);

        return view('purchase_order.revise', compact('currentPo', 'warehouseDDL', 'vendorTruckingDDL'));
    }

    public function saveRevision(Request $request, $id)
    {
        $revisedPO = $this->purchaseOrderService->revisePO($request, $id);

        return redirect(route('db.po.revise.index'));
    }

    public function paymentIndex()
    {
        Log::info('[PurchaseOrderController@paymentIndex]');

        $purchaseOrders = PurchaseOrder::with('supplier')->where('status', '=', 'POSTATUS.WP')->get();
        $poStatusDDL = Lookup::where('category', '=', 'POSTATUS')->get()->pluck('description', 'code');

        return view('purchase_order.payment_index', compact('purchaseOrders', 'poStatusDDL'));
    }

    public function createCashPayment($id)
    {
        Log::info('[PurchaseOrderController@createCashPayment]');

        $currentPo = PurchaseOrder::with('payments', 'items.product.productUnits.unit',
            'supplier.profiles.phoneNumbers.provider', 'supplier.bankAccounts.bank', 'supplier.products', 'supplier.products.type',
            'vendorTrucking', 'warehouse')->find($id);
        $paymentTypeDDL = Lookup::where('category', '=', 'PAYMENTTYPE')->get()->pluck('description', 'code');
        $paymentStatusDDL = Lookup::whereIn('category', ['CASHPAYMENTSTATUS', 'TRFPAYMENTSTATUS', 'GIROPAYMENTSTATUS'])
            ->get()->pluck('description', 'code');
        $paymentType = 'PAYMENTTYPE.C';
        
        return view('purchase_order.cash_payment', compact('currentPo', 'paymentTypeDDL', 'paymentStatusDDL', 'paymentType'));
    }

    public function saveCashPayment(Request $request, $id)
    {
        Log::info('[PurchaseOrderController@saveCashPayment]');

        $cashPayment = new CashPayment();
        $cashPayment->save();

        $paymentParam = [
            'payment_date' => date('Y-m-d', strtotime($request->input('payment_date'))),
            'total_amount' => floatval(str_replace(',', '', $request->input('total_amount'))),
            'status' => Lookup::whereCode('CASHPAYMENTSTATUS.C')->first()->code,
            'type' => Lookup::whereCode('PAYMENTTYPE.C')->first()->code
        ];

        $payment = Payment::create($paymentParam);

        $cashPayment->payment()->save($payment);

        $currentPo = PurchaseOrder::find($id);

        $currentPo->payments()->save($payment);

        $currentPo->updatePaymentStatus();

        return redirect(route('db.po.payment.index'));
    }

    public function createTransferPayment($id)
    {
        Log::info('[PurchaseOrderController@createTransferPayment]');

        $currentPo = PurchaseOrder::with('payments', 'items.product.productUnits.unit',
            'supplier.profiles.phoneNumbers.provider', 'supplier.bankAccounts.bank', 'supplier.products',
            'vendorTrucking', 'warehouse')->find($id);
        $paymentTypeDDL = Lookup::where('category', '=', 'PAYMENTTYPE')->get()->pluck('description', 'code');
        $bankDDL = Bank::all(['id', 'name']);
        $paymentStatusDDL = Lookup::whereIn('category', ['CASHPAYMENTSTATUS', 'TRFPAYMENTSTATUS', 'GIROPAYMENTSTATUS'])
            ->get()->pluck('description', 'code');
        $paymentType = 'PAYMENTTYPE.T';

        return view('purchase_order.transfer_payment', compact('currentPo', 'paymentTypeDDL', 'paymentStatusDDL', 'paymentType', 'bankDDL'));
    }

    public function saveTransferPayment(Request $request, $id)
    {
        Log::info('[PurchaseOrderController@saveTransferPayment]');

        $transferPayment = new TransferPayment();
        $transferPayment->bank_from_id = $request->input('bank_from');
        $transferPayment->bank_to_id = $request->input('bank_to');
        $transferPayment->effective_date = date('Y-m-d', strtotime($request->input('effective_date')));
        $transferPayment->save();

        $paymentParam = [
            'payment_date' => date('Y-m-d', strtotime($request->input('payment_date'))),
            'total_amount' => floatval(str_replace(',', '', $request->input('total_amount'))),
            'status' => Lookup::whereCode('TRFPAYMENTSTATUS.UNCONFIRMED')->first()->code,
            'type' => Lookup::whereCode('PAYMENTTYPE.T')->first()->code
        ];

        $payment = Payment::create($paymentParam);

        $transferPayment->payment()->save($payment);

        $currentPo = PurchaseOrder::find($id);

        $currentPo->payments()->save($payment);

        return redirect(route('db.po.payment.index'));
    }
    
    public function delete(Request $request, $id)
    {
        $this->purchaseOrderService->rejectPO($request, $id);

        return redirect(route('db.po.revise.index'));
    }
}