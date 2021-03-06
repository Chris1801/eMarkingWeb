// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   eMarking
 * @copyright 2013 Jorge Villalón <villalon@gmail.com>
 * 				   Hans C. Jeria <hansj@live.cl>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
package cl.uai.client.page;

import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.logging.Logger;

import org.vaadin.gwtgraphics.client.DrawingArea;

import cl.uai.client.EMarkingComposite;
import cl.uai.client.EMarkingWeb;
import cl.uai.client.MarkingInterface;
import cl.uai.client.marks.CheckMark;
import cl.uai.client.marks.CommentMark;
import cl.uai.client.marks.CrossMark;
import cl.uai.client.marks.CustomMark;
import cl.uai.client.marks.Mark;
import cl.uai.client.marks.PathMark;
import cl.uai.client.marks.QuestionMark;
import cl.uai.client.marks.RubricMark;
import cl.uai.client.resources.Resources;

import com.allen_sauer.gwt.dnd.client.PickupDragController;
import com.google.gwt.canvas.client.Canvas;
import com.google.gwt.event.dom.client.ContextMenuEvent;
import com.google.gwt.event.dom.client.ContextMenuHandler;
import com.google.gwt.user.client.Window;
import com.google.gwt.user.client.ui.AbsolutePanel;
import com.google.gwt.user.client.ui.FocusPanel;
import com.google.gwt.user.client.ui.Image;
import com.google.gwt.user.client.ui.PopupPanel;
import com.google.gwt.user.client.ui.ScrollPanel;
import com.google.gwt.user.client.ui.ToggleButton;


/**
 * @author Jorge Villalon <villalon@gmail.com>
 *
 */
public class MarkingPage extends EMarkingComposite implements ContextMenuHandler {

	public int getWidth() {
		return width;
	}

	public int getHeight() {
		return height;
	}

	/** For loggin purposes **/
	Logger logger = Logger.getLogger(MarkingPage.class.getName());

	private FocusPanel mainPanel = null;
	private AbsolutePanel absolutePanel = null;
	private Canvas canvas = null;
	private DrawingArea drawingArea = null;

	/** Drag and Drop controller **/
	private PickupDragController dragController = null;
	private DrawController drawController = null;
	private MarkingPageDropController dropController = null;

	/** Page image and number within submission **/
	private Image pageImage = null;
	private int pageNumber = -1;

	public int getPageNumber() {
		return pageNumber;
	}
	
	private int width;
	private int height;

	/** All marks in this page **/
	private Map<Integer, Mark> marks = null;

	/**
	 * 
	 */
	public MarkingPage(int pagenum, String image, int width, int height, List<Map<String, String>> pageMarks) {
		this.pageNumber = pagenum;
		this.pageImage = new Image(image);
		this.width = width;
		this.height = height;
		this.pageImage.setWidth(this.width + "px");
		this.pageImage.setHeight(this.height + "px");

		logger.fine("Adding page " + pagenum + " Url:" + image);

		this.marks = new HashMap<Integer, Mark>();
		
		for(Map<String, String> markMap : pageMarks) {
			
			if(MarkingInterface.getLinkRubric() == 0)
				markMap.put("colour", "criterion0");
			try {
				int format = Integer.parseInt(markMap.get("format"));
				fixPositions(markMap, width, height);
				Mark mark = null;
			switch(format) {
			case 1:
				mark = CommentMark.createFromMap(markMap);
				break;
			case 2:
				mark = RubricMark.createFromMap(markMap);
				break;
			case 3:
				mark = CheckMark.createFromMap(markMap);
				break;
			case 4:
				mark = CrossMark.createFromMap(markMap);
				break;
			case 5:
				mark = PathMark.createFromMap(markMap);
				break;
			case 6:
				mark = QuestionMark.createFromMap(markMap);
				break;
			case 1000:
				mark = CustomMark.createFromMap(markMap);
				break;
			default:
				logger.severe("Invalid format for comment");
				mark = null;
				break;
			}
			
			if(mark !=null) {
				this.marks.put(mark.getId(), mark);
			}
			
			} catch(Exception e) {
				e.printStackTrace();
				logger.severe("Exception creating mark from DB. " + markMap.toString() + " Error:"+e.getMessage());
			}
		}

		
		mainPanel = new FocusPanel();
		absolutePanel = new AbsolutePanel();
		absolutePanel.addStyleName(Resources.INSTANCE.css().absolutepage());
		drawingArea  = new DrawingArea(width, height);
		
		mainPanel.addClickHandler(new MarkingPageClickHandler(this));


		dropController = new MarkingPageDropController(absolutePanel, this);
		EMarkingWeb.markingInterface.getDragController().registerDropController(dropController);

		// Initialize drag and drop controller, attached to the absolute panel
		dragController = new PickupDragController(absolutePanel, true);
		dragController.setBehaviorDragStartSensitivity(1);
		dragController.setBehaviorScrollIntoView(false); // TODO: Check this parameter
		MarkingPageDragHandler dragHandler = new MarkingPageDragHandler(absolutePanel, this);
		dragController.addDragHandler(dragHandler);

		//Initialize Drawing controller for pen tool
		drawController = new DrawController();
		//The draw controller listens to the drawing area
		drawController.listenTo(mainPanel);
		//The drag handler listens to the draw controller
		MarkingPageDrawHandler drawHandler = new MarkingPageDrawHandler(absolutePanel, drawingArea, this);
		drawController.addListener(drawHandler);
		absolutePanel.add(drawingArea,0,0);
		
		absolutePanel.add(pageImage);
		canvas = Canvas.createIfSupported();
		if(canvas != null) {
			logger.fine("Canvas added!");
			canvas.setSize(width+"px", height+"px");
			canvas.setCoordinateSpaceWidth(width);
			canvas.setCoordinateSpaceHeight(height);
			canvas.addStyleName(Resources.INSTANCE.css().pagecanvas());
			absolutePanel.add(canvas, 0, 0);
		}

		mainPanel.add(absolutePanel);
		
		this.initWidget(mainPanel);
		
		addDomHandler(this, ContextMenuEvent.getType());
	}
	
	/**
	 * Fixes positions coming from Moodle in a 0-1 format to the actual page size
	 * @param map
	 * @param width
	 * @param height
	 */
	private void fixPositions(Map<String, String> map, int width, int height) {
		int posx = (int) (Float.parseFloat(map.get("posx")) * (float) width);
		int posy = (int) (Float.parseFloat(map.get("posy")) * (float) height);
		map.remove("posx");
		map.remove("posy");
		map.put("posx", Integer.toString(posx));
		map.put("posy", Integer.toString(posy));
	}
	
	/**
	 * Gets statistics of each mark type in the page
	 * 
	 * @return a hash map with mark id, total appearances pairs
	 */
	public Map<Integer, Integer> getMarkStatistics() {
		Map<Integer, Integer> stats = new HashMap<Integer, Integer>();
		
		List<ToggleButton> buttons = EMarkingWeb.markingInterface.getToolbar().getMarkingButtons().getButtons();
		
		for(int i=1;i<=buttons.size();i++) {
			stats.put(i, 0);
		}
		
		if(this.marks == null)
			return stats;
		
		for(int markId : this.marks.keySet()) {
			Mark mark = this.marks.get(markId);
			
			int format = mark.getFormat();
			
			if(format == 1000) {
				String label = mark.getRawtext();
				Integer idx = EMarkingWeb.markingInterface.getToolbar().getMarkingButtons().getCustomButtonIndex().get(label);
				if(idx == null || idx < 6) {
					logger.severe("Custom mark " + label + " index not found");
					continue;
				} else {
					format = idx;
				}
			}
			
			int newvalue = 0;
			if(stats.containsKey(format)) {
				newvalue = stats.get(format);
				stats.remove(format);
			}
			
			int c = EMarkingWeb.markingInterface.getToolbar().getMarkingButtons().getIndexSelectedCriterion();
			String criterionid = "criterion"+c;
			if(c == 0){
				newvalue++;
			}else if(mark.getColour().equals(criterionid)){
				newvalue++;
			}
			stats.put(format, newvalue);
			
		}
		
		return stats;
	}

	// Returns the absolute panel for coordinates calculation when dropping new mark
	public AbsolutePanel getAbsolutePanel() {
		return absolutePanel;
	}

	@Override
	protected void onLoad() {
		super.onLoad();
		//TODO: Ancho de la prueba
		// Once loaded, we know all sizes
		int i= (int)(Window.getClientWidth());
		String ancho = String.valueOf(i);
		mainPanel.setWidth(ancho);
		mainPanel.addStyleName(Resources.INSTANCE.css().pagescroll());
		loadInterface();
	}
	
	public void loadInterface() {
		for(Mark mark : this.marks.values()) {
			addMarkWidget(mark);
		}
	}

	/**
	 * The marks in this page
	 * 
	 * @return hashmap with marks (mark id as key)
	 */
	public Map<Integer, Mark> getMarkWidgets() {
		return marks;
	}

	/**
	 * Adds a mark to this page
	 * 
	 * @param mark Mark object
	 * @return same Mark object
	 */
	public Mark addMarkWidget(final Mark mark) {
		// Adds Mark to hashmap
		marks.put(mark.getId(), mark);

		// Make the mark draggable within page if not in readonly mode
		if(!(mark instanceof PathMark)) {
			dragController.makeDraggable(mark);
		}
		absolutePanel.add(mark, mark.getPosx(), mark.getPosy());

		// drawCommentLine(mark);

		return mark;
	}

	// TODO: Add free lines on canvas
	/**
	 * Draws a line from the mark to the right end of the page panel
	 * 
	 * @param mark the mark
	 */
	@SuppressWarnings("unused")
	private void drawCommentLine(Mark mark) {
		if(this.canvas != null) {
			
			// Calculate coordinates for both points
			int x = mark.getPosx() + mark.getOffsetWidth();
			int y = mark.getPosy() + (int) ((float) mark.getOffsetHeight() / 2);
			int x2 = this.canvas.getCoordinateSpaceWidth();
			int y2 = y;

			logger.fine("Drawing line from " + x + "," + y + " to " + x2 + "," + y2);

			// Draw the line
			this.canvas.getContext2d().setLineWidth(0.2d);
			this.canvas.getContext2d().setShadowColor("#fff");
			this.canvas.getContext2d().setStrokeStyle("#ff0000");
			this.canvas.getContext2d().moveTo(x, y);
			this.canvas.getContext2d().lineTo(x2, y2);
			this.canvas.getContext2d().stroke();
			this.canvas.getContext2d().save();
		}
	}

	/**
	 * Removes a Mark object from this page by its id
	 * @param id mark id number
	 */
	public void deleteMarkWidget(int id) {
		// Checks if the mark exists within the page
		Mark mark = marks.get(id);

		// If exists remove from panel and from hashmap
		if(mark != null) {
			absolutePanel.remove(mark);
			marks.remove(id);
		}
	}

	/**
	 * Highlight a particular mark within the page
	 * 
	 * @param markId the mark's id
	 */
	public void highlightRubricMark(int markId) {
		if(!(marks.get(markId) instanceof RubricMark)) {
			return;
		}
		RubricMark mark = (RubricMark) marks.get(markId);
		ScrollPanel scrollPanel = EMarkingWeb.markingInterface.getMarkingPagesInterface().getScrollPanel();
		int top = scrollPanel.getVerticalScrollPosition() + (mark.getAbsoluteTop() - scrollPanel.getAbsoluteTop());
		scrollPanel.setVerticalScrollPosition(top);
	}


	public RubricMark getMarkByLevelId(int lvlid) {
		RubricMark mark = null;
		
		for(Mark m : marks.values()) {
			if(m instanceof RubricMark) {
				RubricMark rmark = (RubricMark) m;
				if(rmark.getLevelId() == lvlid)
					return rmark;
			}
		}
		return mark;
	}
	
	/**
	+	 * Count how many rubric marks has the page.
	+	 * @return allMarksInPage
	+	 */
	public int isHaveRubricMark(){
		int allMarksInPage = 0;
		for(Mark m : marks.values()) {
			if(m instanceof RubricMark) {
				allMarksInPage++;
			}
		}
		
		return allMarksInPage;
	}

	@Override
	public void onContextMenu(ContextMenuEvent event) {
		event.getNativeEvent().stopPropagation();
        event.getNativeEvent().preventDefault();
        
        int posx = event.getNativeEvent().getClientX();
        int posy = event.getNativeEvent().getClientY();
        
        PopupPanel menu = new MarkingMenu(this, posx, posy);
        menu.show();
	}
}
