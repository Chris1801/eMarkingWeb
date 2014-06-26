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
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
package cl.uai.client.toolbar;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.logging.Logger;

import cl.uai.client.EMarkingComposite;
import cl.uai.client.EMarkingWeb;
import cl.uai.client.MarkingInterface;
import cl.uai.client.resources.Resources;

import com.github.gwtbootstrap.client.ui.Icon;
import com.github.gwtbootstrap.client.ui.constants.IconType;
import com.google.gwt.dom.client.Style.Cursor;
import com.google.gwt.event.logical.shared.ValueChangeEvent;
import com.google.gwt.event.logical.shared.ValueChangeHandler;
import com.google.gwt.user.client.ui.AbsolutePanel;
import com.google.gwt.user.client.ui.HorizontalPanel;
import com.google.gwt.user.client.ui.Label;
import com.google.gwt.user.client.ui.ToggleButton;

/**
 * @author Jorge Villalón <villalon@gmail.com>
 *
 */
public class MarkingButtons extends EMarkingComposite {

	/** Logger **/
	private static Logger logger = Logger.getLogger(MarkingButtons.class.getName());

	/** Main panel holding the buttons **/
	private HorizontalPanel mainPanel = null;

	/** The buttons **/
	private List<ToggleButton> buttons = null;

	private Map<String, Integer> customButtonIndex = null;
	/**
	 * @return the buttons
	 */
	public List<ToggleButton> getButtons() {
		return buttons;
	}

	/** Selected index **/
	private int selectedIndex = 0;

	private EmarkingToolBarValueChangeHandler handler = new EmarkingToolBarValueChangeHandler();
	/**
	 * Available buttons in marking interface
	 * 
	 * @author Jorge Villalón <villalon@gmail.com>
	 *
	 */
	public enum Buttons {
		BUTTON_RUBRIC,
		BUTTON_COMMENT,
		BUTTON_TICK,
		BUTTON_CROSS,
		BUTTON_PEN,
		BUTTON_CUSTOM,
	}

	/**
	 * The icon for each button
	 */
	private static IconType[] iconTypes = {
		IconType.TH,
		IconType.COMMENT,
		IconType.OK,
		IconType.REMOVE,
		IconType.PENCIL,
	};
	
	/**
	 * The format codes for each button
	 */
	private static int[] buttonFormats = {
		2,
		1,
		3,
		4,
		5
	};

	/**
	 * Title for each button
	 */
	private static String[] buttonsTitles = {
		MarkingInterface.messages.RubricTitle(),
		MarkingInterface.messages.CommentTitle(),
		MarkingInterface.messages.CheckTitle(),
		MarkingInterface.messages.CrossTitle(),
		MarkingInterface.messages.PenTitle(),
	};

	/**
	 * Stats like in Facebook jewels
	 */
	private Map<Integer, Label> buttonsStats;

	/**
	 * If the button is shown
	 */
	private static boolean[] buttonsShow = {
		true,
		true,
		true,
		true,
		true,
	};

	/**
	 * Creates the rubric buttons interface
	 */
	public MarkingButtons() {
		// main panel contains all buttons
		mainPanel = new HorizontalPanel();
		mainPanel.addStyleName(Resources.INSTANCE.css().toolbarbuttons());

		// Initialize the array
		buttons = new ArrayList<ToggleButton>();
		buttonsStats = new HashMap<Integer, Label>();

		// Creates all buttons and adds a general value change handler

		// First creates all buttonstats as they have to be referenced by buttons
		for(int i=0;i<iconTypes.length;i++) {

			if(!buttonsShow[i])
				continue;

			Label lblstat = new Label();
			lblstat.addStyleName(Resources.INSTANCE.css().rubricbuttonjewel());
			buttonsStats.put(buttonFormats[i], lblstat);
		}

		// Second we create all buttons and reference the to their stats
		for(int i=0;i<iconTypes.length;i++) {

			if(!buttonsShow[i])
				continue;

			addToggleButton(
					getButtonHtml(Buttons.values()[i]), 
					buttonsTitles[i], 
					Resources.INSTANCE.css().rubricbutton(),
					buttonFormats[i]);
		}

		buttons.get(selectedIndex).setValue(true);

		this.initWidget(mainPanel);
	}

	/**
	 * Handles when a button was clicked, selecting it and deselecting others in the toolbar
	 * 
	 * @author Jorge Villalón <villalon@gmail.com>
	 *
	 */
	private class EmarkingToolBarValueChangeHandler implements ValueChangeHandler<Boolean> {
		@Override
		public void onValueChange(ValueChangeEvent<Boolean> event) {
			ToggleButton tbutton = (ToggleButton) event.getSource();
			pushButton(buttons.indexOf(tbutton), true);
			EMarkingWeb.markingInterface.getElement().getStyle().setCursor(Cursor.DEFAULT);
		}
	}

	private void pushButton(int index, boolean fromEvent) {
		if(index >= buttons.size())
			return;
		
		ToggleButton tbutton = buttons.get(index);

		if(tbutton == null || (fromEvent && !tbutton.isDown())) {
			tbutton.setValue(true);
			return;
		}

		for(int i=0; i < buttons.size(); i++) {
			ToggleButton button = buttons.get(i);
			if(button.isDown() && i != index) {
				button.setValue(false);
			}
			if(i == index) {
				button.setValue(true);
			}
		}

		selectedIndex = index;
	}

	/**
	 * 
	 * @return the selected button in the toolbar
	 */
	public Buttons getSelectedButton() {
		if(selectedIndex < iconTypes.length)
			return Buttons.values()[selectedIndex];
		else
			return Buttons.BUTTON_CUSTOM;
	}

	/**
	 * 
	 * @param button
	 * @return the HTML to draw the icon of a specific button
	 */
	public static String getButtonHtml(Buttons button) {
		Icon icon = new Icon(iconTypes[button.ordinal()]);
		return icon.toString();
	}

	/**
	 * 
	 */
	public void updateStats() {
		Map<Integer, Integer> stats = EMarkingWeb.markingInterface.getMarkingPagesInterface()
				.getMarkStatistics();

		for(int format : stats.keySet()) {
			int value = stats.get(format);

			buttonsStats.get(format).setText(Integer.toString(value));

			if(value > 0) {
				buttonsStats.get(format).setVisible(true);
			} else {
				buttonsStats.get(format).setVisible(false);
			}
		}
	}

	public void loadCustomMarksButtons(String customMarks) {
		if(customMarks == null || customMarks.trim().length() == 0 || buttons.size() >= Buttons.values().length)
			return;
		
		String[] lines = customMarks.replaceAll("\r\n", "\n").split("\n");
		String customButtons = "";
		String customButtonsTitles = "";
		for(int i=0;i<lines.length;i++) {
			String[] lineparts = lines[i].split("#");
			if(lineparts.length != 2)
				continue;
			customButtons += lineparts[0] + ",";
			customButtonsTitles += lineparts[1] + ",";
		}
		
		customButtonIndex = new HashMap<String, Integer>();

		String[] partsButtonLabels = customButtons.split(",");
		String[] partsButtonTitles = customButtonsTitles.split(",");

		if(partsButtonLabels.length != partsButtonTitles.length) {
			logger.severe("Invalid parameters loading custom buttons");
			return;
		}
		
		int basebuttons = buttons.size();
		for(int j=0;j<partsButtonLabels.length;j++) {
			if(partsButtonLabels[j].trim().length()>0) {
				addToggleButton(
						partsButtonLabels[j], 
						partsButtonTitles[j], 
						Resources.INSTANCE.css().rubricbuttoncustom(),
						basebuttons + j);
				customButtonIndex.put(partsButtonLabels[j], basebuttons + j);
			}
		}
	}

	/**
	 * @return the customButtonIndex
	 */
	public Map<String, Integer> getCustomButtonIndex() {
		return customButtonIndex;
	}

	/**
	 * Adds a toggle button to the toolbar
	 * 
	 * @param label
	 * @param title
	 * @param cssStyle
	 * @param format
	 */
	private void addToggleButton(String label, String title, String cssStyle, int format) {
		ToggleButton button = new ToggleButton();
		button.addStyleName(cssStyle);
		button.setHTML(label);			
		button.addValueChangeHandler(handler);
		button.setTitle(title);
		buttons.add(button);

		Label lblstat = buttonsStats.get(format);
		
		AbsolutePanel vpanel = new AbsolutePanel();
		vpanel.add(button);
		vpanel.add(lblstat, 19, 2);
		mainPanel.add(vpanel);	
	}

	public String getSelectedButtonLabel() {
		ToggleButton btn = this.buttons.get(selectedIndex);
		return btn.getHTML();
	}

	public String getSelectedButtonTitle() {
		ToggleButton btn = this.buttons.get(selectedIndex);
		return btn.getTitle();
	}

	public void setButtonPressed(int index, boolean fromEvent) {
		pushButton(index, fromEvent);
	}
}

